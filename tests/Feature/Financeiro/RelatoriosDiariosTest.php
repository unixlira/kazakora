<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Financeiro\Mail\ResultadoDoDia;
use App\Modules\Financeiro\Support\ResultadoDiario;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceClaim;
use App\Modules\Operacional\Mail\CanceladosSeparados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Resultado do dia (23:50) e alerta de separados cancelados (pedido 2026-10-10). */
class RelatoriosDiariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['loja.email_relatorios' => 'dono@exemplo.com', 'loja.simples.aliquota' => 4.5, 'loja.simples.desde' => '2026-09-01']);
        Carbon::setTestNow('2026-10-10 23:50:00');
    }

    private function pedido(array $extra = [], float $custo = 40): Order
    {
        $produto = Product::factory()->create(['price' => 100, 'cost_price' => $custo, 'stock' => 10]);
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => 'site',
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 100,
            ...$extra,
        ]);
        $pedido->items()->create(['product_id' => $produto->id, 'product_name' => 'Organizador', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $pedido;
    }

    public function test_lucro_do_dia_do_mes_e_impostos(): void
    {
        $this->pedido();
        $ontem = $this->pedido();
        $ontem->forceFill(['created_at' => now()->subDay()])->save();
        $this->pedido(['status' => 'cancelled']);

        $r = app(ResultadoDiario::class)->calcular(now());

        // 100 de receita − 40 de custo − 4,50 de imposto (4,5%).
        $this->assertSame(1, $r['dia']['pedidos']);
        $this->assertEqualsWithDelta(55.5, $r['dia']['lucro'], 0.001);
        $this->assertEqualsWithDelta(111.0, $r['mes']['lucro'], 0.001);
        $this->assertEqualsWithDelta(9.0, $r['mes']['impostos'], 0.001);
        $this->assertSame('2026-11-20', $r['vencimento_das']->toDateString());
    }

    public function test_sem_imposto_antes_de_entrar_no_simples(): void
    {
        config(['loja.simples.desde' => '2026-10-11']);
        $this->pedido();

        $this->assertEqualsWithDelta(0.0, app(ResultadoDiario::class)->calcular(now())['dia']['impostos'], 0.001);
    }

    public function test_comando_envia_uma_vez_por_dia(): void
    {
        Mail::fake();
        $this->pedido();

        $this->artisan('loja:resultado-diario')->assertSuccessful();
        $this->artisan('loja:resultado-diario')->assertSuccessful();

        Mail::assertSent(ResultadoDoDia::class, 1);
        Mail::assertSent(ResultadoDoDia::class, fn ($mail) => $mail->hasTo('dono@exemplo.com'));
        $this->assertStringContainsString('Lucro líquido do dia', (new ResultadoDoDia(app(ResultadoDiario::class)->calcular(now())))->render());
    }

    public function test_alerta_de_separado_cancelado_sem_repetir(): void
    {
        Mail::fake();
        $this->pedido(['origin' => 'shopee', 'status' => 'cancelled', 'packed_at' => now()->subHours(3), 'external_order_id' => 'SHP1']);
        $this->pedido(['origin' => 'shopee', 'status' => 'cancelled']);

        $this->artisan('loja:cancelados-separados')->assertSuccessful();
        $this->artisan('loja:cancelados-separados')->assertSuccessful();

        Mail::assertSent(CanceladosSeparados::class, 1);
        Mail::assertSent(CanceladosSeparados::class, fn ($mail) => count($mail->pedidos) === 1 && $mail->pedidos[0]['external_order_id'] === 'SHP1');
    }

    public function test_alerta_ignora_pacote_que_ja_saiu(): void
    {
        Mail::fake();
        $base = ['origin' => 'shopee', 'status' => 'cancelled', 'packed_at' => now()->subDays(4)];
        $this->pedido([...$base, 'external_order_id' => 'NA_EXPEDICAO']);
        $devolucao = $this->pedido([...$base, 'external_order_id' => 'TO_RETURN']);
        MarketplaceClaim::create(['order_id' => $devolucao->id, 'channel' => 'shopee', 'external_claim_id' => 'TO_RETURN', 'type' => 'return', 'stage' => 'to_return', 'status' => 'to_return', 'claim_created_at' => now()]);
        $coletado = $this->pedido([...$base, 'external_order_id' => 'COLETADO']);
        app(OrderFulfillmentTimeline::class)->record($coletado, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Pedido saiu da fila de preparação do KoraSync — shopee confirmou "shipped" (coleta/despacho real do pacote).');
        $emRota = $this->pedido([...$base, 'origin' => 'mercado_livre', 'external_order_id' => 'EM_ROTA']);
        ChannelShipment::create(['order_id' => $emRota->id, 'channel' => 'mercado_livre', 'channel_shipped_at' => now()->subDays(3)]);
        $this->pedido([...$base, 'origin' => 'mercado_livre', 'external_order_id' => 'FLEX', 'collected_at' => now()->subDays(3)]);

        $this->artisan('loja:cancelados-separados')->assertSuccessful();

        Mail::assertSent(CanceladosSeparados::class, fn ($mail) => array_column($mail->pedidos, 'external_order_id') === ['NA_EXPEDICAO']);
    }

    public function test_estao_na_agenda(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('loja:resultado-diario')->expectsOutputToContain('loja:cancelados-separados');
    }
}

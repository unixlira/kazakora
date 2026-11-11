<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Mail\ResultadoDoDia;
use App\Modules\Financeiro\Support\ResultadoDiario;
use App\Modules\Operacional\Mail\CanceladosSeparados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Resultado do dia (06:30, dia anterior fechado) e alerta de separados cancelados (pedido 2026-10-10). */
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

    public function test_manda_de_manha_o_dia_anterior_e_lista_os_dias_que_mudaram(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-11 06:30:00');
        $dia10 = $this->pedido();
        $dia10->forceFill(['created_at' => '2026-10-10 15:00:00'])->save();
        $dia9 = $this->pedido();
        $dia9->forceFill(['created_at' => '2026-10-09 15:00:00'])->save();
        // 09/10 saiu às 23:50 com 80,00; depois chegaram anúncio/taxa e hoje vale 55,50.
        Storage::disk('local')->put('automation/kazakora_margin_daily_email_state.json', json_encode([
            '2026-10-09' => ['sent_at' => '2026-10-09T23:50:00-03:00', 'lucro' => 80.0],
            '2026-10-10' => ['sent_at' => '2026-10-10T23:50:00-03:00', 'lucro' => 10.0],
        ]));

        $this->artisan('loja:resultado-diario')->assertSuccessful();

        Mail::assertSent(ResultadoDoDia::class, function (ResultadoDoDia $mail) {
            $r = $mail->resultado;
            $this->assertSame('2026-10-10', $r['data']->toDateString());
            $this->assertEqualsWithDelta(55.5, $r['dia']['lucro'], 0.001);
            $this->assertCount(1, $r['revisoes']);
            $this->assertSame('2026-10-09', $r['revisoes'][0]['data']->toDateString());
            $this->assertEqualsWithDelta(-24.5, $r['revisoes'][0]['diferenca'], 0.001);
            $html = $mail->render();
            $this->assertStringContainsString('Dias que mudaram desde o último e-mail', $html);
            $this->assertStringContainsString('− R$ 24,50', $html);

            return true;
        });

        // Base nova: o dia 09 passa a valer o que foi mostrado; o 10 fica fechado e não repete.
        $controle = json_decode(Storage::disk('local')->get('automation/kazakora_margin_daily_email_state.json'), true);
        $this->assertEqualsWithDelta(55.5, $controle['2026-10-09']['lucro'], 0.001);
        $this->assertTrue($controle['2026-10-10']['fechado']);
        $this->artisan('loja:resultado-diario')->assertSuccessful();
        Mail::assertSent(ResultadoDoDia::class, 1);
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

    public function test_estao_na_agenda(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('loja:resultado-diario')->expectsOutputToContain('loja:cancelados-separados');
    }
}

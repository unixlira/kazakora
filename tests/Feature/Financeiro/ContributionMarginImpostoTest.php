<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Support\ResultadoDiario;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Validação financeira 2026-10-18: imposto do Simples e GMV Max do TikTok na
 * conta oficial, e o painel, o Financeiro e o e-mail com o MESMO lucro.
 */
class ContributionMarginImpostoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 4.5, 'loja.simples.desde' => '2026-09-01']);
        Carbon::setTestNow('2026-10-18 15:00:00');
    }

    private function pedido(string $origem, float $subtotal, float $custo = 40, array $extra = []): Order
    {
        $produto = Product::factory()->create(['price' => $subtotal, 'cost_price' => $custo, 'stock' => 10]);
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => $origem,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $subtotal, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => $subtotal,
            ...$extra,
        ]);
        $pedido->items()->create(['product_id' => $produto->id, 'product_name' => 'Item', 'product_price' => $subtotal, 'quantity' => 1, 'subtotal' => $subtotal, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $pedido;
    }

    private function gmv(string $statement, string $data, float $valor, string $status = 'Pagos', string $arquivo = 'a.xlsx', ?string $sku = ''): void
    {
        DB::table('marketplace_settlement_details')->insert([
            'channel' => 'tiktok_shop', 'statement_id' => $statement, 'status' => $status,
            'transaction_type' => 'Pagamento de GMV para anúncios do TikTok', 'external_order_id' => '7639742860367250197',
            'external_sku_id' => $sku, 'settlement_date' => $data, 'payout_amount' => -$valor, 'adjustment_amount' => -$valor,
            'source_file' => $arquivo, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_lucro_tira_o_imposto_sobre_a_nota_e_o_gmv_max_do_tiktok(): void
    {
        $shopee = $this->pedido('shopee', 200);
        // Nota de R$ 180 (cupom da plataforma abateu): o Simples sai sobre ela.
        DB::table('invoices')->insert(['order_id' => $shopee->id, 'numero' => 1, 'status' => 'authorized', 'operation_type' => 'saida', 'valor_total' => 180, 'created_at' => now(), 'updated_at' => now()]);
        $this->pedido('tiktok_shop', 100);
        $this->gmv('st-1', '2026-10-17', 27);
        // Mesmo lançamento com o SKU "/" (outro formato do arquivo): conta uma vez.
        $this->gmv('st-1', '2026-10-17', 27, sku: '/');
        $this->gmv('st-2', '2026-10-05', 10);
        $this->gmv('st-9', '2026-09-30', 99);

        $conta = app(ContributionMargin::class)->periodo(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-19'));

        $this->assertEqualsWithDelta(300.0, $conta['receita'], 0.001);
        $this->assertEqualsWithDelta(37.0, $conta['ads'], 0.001, 'GMV Max de outubro, uma vez por demonstrativo');
        // 4,5% de 180 (nota) + 4,5% de 100 (sem nota, vai a receita) = 8,10 + 4,50.
        $this->assertEqualsWithDelta(12.6, $conta['imposto'], 0.001);
        // 300 − 80 de custo − 37 de ADS = 183 de margem; − 12,60 = 170,40.
        $this->assertEqualsWithDelta(183.0, $conta['margem'], 0.001);
        $this->assertEqualsWithDelta(170.4, $conta['lucro'], 0.001);

        $canal = app(ContributionMargin::class)->porCanal(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-19'));
        $this->assertEqualsWithDelta(37.0, $canal['tiktok_shop']['ads'], 0.001);
        $this->assertEqualsWithDelta(4.5, $canal['tiktok_shop']['imposto'], 0.001);
        $this->assertEqualsWithDelta(8.1, $canal['shopee']['imposto'], 0.001);
        $this->assertEqualsWithDelta($conta['lucro'], array_sum(array_column($canal, 'lucro')), 0.001, 'soma dos canais = total');
    }

    public function test_sem_imposto_antes_de_entrar_no_simples(): void
    {
        $antigo = $this->pedido('shopee', 100);
        $antigo->forceFill(['created_at' => '2026-08-20 10:00:00'])->save();

        $conta = app(ContributionMargin::class)->periodo(Carbon::parse('2026-08-01'), Carbon::parse('2026-09-01'));

        $this->assertEqualsWithDelta(0.0, $conta['imposto'], 0.001);
        $this->assertEqualsWithDelta($conta['margem'], $conta['lucro'], 0.001);
    }

    public function test_painel_financeiro_e_email_mostram_o_mesmo_lucro(): void
    {
        $this->pedido('shopee', 150, 50);
        $this->pedido('mercado_livre', 80, 30);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $conta = app(ContributionMargin::class)->periodo(Carbon::today()->startOfMonth(), Carbon::tomorrow());
        $email = app(ResultadoDiario::class)->calcular(Carbon::today())['mes'];

        $this->actingAs($admin)->get('/admin/indicadores?aba=hoje-e-mes')->assertOk()->assertInertia(fn ($page) => $page
            ->where('hojeMes.stats.netProfitMonth', $conta['lucro'])
            ->where('hojeMes.stats.taxMonth', $conta['imposto']));
        // Fluxo de Caixa (2026-10-19): mesmo lucro, mesmo imposto.
        $this->actingAs($admin)->get('/admin/fluxo-de-caixa')->assertOk()->assertInertia(fn ($page) => $page
            ->where('resumo.atual.lucro', $conta['lucro'])
            ->where('resumo.atual.imposto', $conta['imposto']));
        $this->actingAs($admin)->get('/admin/dashboard-financeiro')->assertOk()->assertInertia(fn ($page) => $page
            ->where('conta.lucro', $conta['lucro'])
            ->where('conta.imposto', $conta['imposto']));

        $this->assertEqualsWithDelta(139.65, $conta['lucro'], 0.001); // 230 − 80 − 10,35
        $this->assertEqualsWithDelta($conta['lucro'], $email['lucro'], 0.001);
        $this->assertEqualsWithDelta($conta['imposto'], $email['impostos'], 0.001);
    }
}

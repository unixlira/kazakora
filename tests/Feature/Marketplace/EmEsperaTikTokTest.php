<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pedidos do TikTok ainda não liquidados com os valores que o próprio TikTok
 * prevê (Finanças › Em espera) — pedido do Lira 04/11/2026. Recorte real da
 * tela lida em 04/11 (tests/fixtures/tiktok-em-espera.json).
 */
class EmEsperaTikTokTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.tiktok_reports.token' => 'segredo-teste']);
    }

    private function pedidos(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/tiktok-em-espera.json')), true)['pedidos'];
    }

    private function pedido(string $externo, float $total, float $subtotal): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_TIKTOK_SHOP, 'external_order_id' => $externo,
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01001000', 'shipping_street' => 'R', 'shipping_number' => '1',
            'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP', 'subtotal' => $subtotal, 'total' => $total,
        ]);
    }

    private function receita(Order $pedido): float
    {
        return (float) DB::table('orders')->where('id', $pedido->id)->value(DB::raw(ContributionMargin::receitaSql()));
    }

    public function test_caixa_em_espera_passa_a_valer_o_previsto_pelo_tiktok(): void
    {
        // Caixa 85 (#3797): cliente pagou 374,92, cheio 715,90; o TikTok repõe o
        // desconto dele e prevê vendas 499,90, taxas 90,98, a receber 408,92.
        $caixa = $this->pedido('586956008411923903', 374.92, 715.90);
        OrderChannelFee::create(['order_id' => $caixa->id, 'channel' => 'tiktok_shop', 'gross_amount' => 715.90, 'fee_amount' => 119.51, 'source' => OrderChannelFee::SOURCE_ESTIMATE, 'computed_at' => now()]);
        // Com frete: vendas 49,99, frete 26,40, taxas 18,00, a receber 5,59.
        $comFrete = $this->pedido('586757941588297673', 49.99, 49.99);
        // Amostra grátis: o TikTok não prevê venda nenhuma.
        $amostra = $this->pedido('586951252437730927', 55.13, 95.90);
        $this->assertSame(374.92, $this->receita($caixa), 'antes: o que o cliente pagou');

        $this->withHeader('Authorization', 'Bearer segredo-teste')
            ->postJson('/api/tiktok/em-espera', ['pedidos' => $this->pedidos()])
            ->assertOk()
            ->assertJson(['ok' => true, 'recebidos' => 3, 'gravados' => 3, 'sem_pedido' => []]);

        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $caixa->id, 'source' => 'previsto', 'gross_amount' => 499.90, 'fee_amount' => 90.98, 'service_fee' => 90.98, 'payout_amount' => 408.92]);
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $comFrete->id, 'source' => 'previsto', 'gross_amount' => 49.99, 'fee_amount' => 44.40, 'shipping_fee' => 26.40, 'payout_amount' => 5.59]);
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $amostra->id, 'source' => 'previsto', 'gross_amount' => 0, 'fee_amount' => 0, 'payout_amount' => 0]);
        $this->assertSame(499.90, $this->receita($caixa));
        $this->assertSame(0.0, $this->receita($amostra));

        // A estimativa de 10 em 10 min não troca o previsto de volta.
        $this->artisan('orders:estimar-taxas-tiktok')->assertSuccessful();
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $caixa->id, 'source' => 'previsto', 'fee_amount' => 90.98]);
    }

    public function test_nao_toca_taxa_real_nem_pedido_ja_no_extrato(): void
    {
        $manual = $this->pedido('586956008411923903', 374.92, 715.90);
        OrderChannelFee::create(['order_id' => $manual->id, 'channel' => 'tiktok_shop', 'gross_amount' => 715.90, 'fee_amount' => 50, 'source' => OrderChannelFee::SOURCE_MANUAL, 'computed_at' => now()]);
        $liquidado = $this->pedido('586757941588297673', 49.99, 49.99);
        DB::table('marketplace_settlement_details')->insert([
            'channel' => 'tiktok_shop', 'statement_id' => '1', 'transaction_type' => 'Pedido', 'external_order_id' => '586757941588297673',
            'external_sku_id' => 'S', 'status' => 'Pagos', 'product_net_sales' => 49.99, 'payout_amount' => 6.00, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('tiktok:em-espera-importar', ['arquivo' => base_path('tests/fixtures/tiktok-em-espera.json')])
            ->expectsOutputToContain('0 gravado(s), 1 já no extrato, 1 com taxa real, 1 sem pedido')
            ->assertSuccessful();

        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $manual->id, 'source' => 'manual', 'fee_amount' => 50]);
        $this->assertDatabaseMissing('order_channel_fees', ['order_id' => $liquidado->id]);
        // Pedido no extrato: receita continua sendo a do extrato.
        $this->assertSame(49.99, $this->receita($liquidado));
    }

    public function test_token_errado_e_recusado(): void
    {
        $this->withHeader('Authorization', 'Bearer outro')
            ->postJson('/api/tiktok/em-espera', ['pedidos' => $this->pedidos()])
            ->assertUnauthorized();
    }
}

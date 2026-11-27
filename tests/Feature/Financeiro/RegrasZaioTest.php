<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Support\ResultadoDiario;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\LucroPorPedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regras da planilha da Zaio (pedido do Lira em 27/11/2026): em Shopee,
 * Mercado Livre e TikTok a comissão da Zaio (2% da receita) sai como custo,
 * a partir de loja.zaio.desde. O imposto é o Simples em todos os canais
 * ("nosso imposto é 4,5%"). Amazon e sites sem comissão. Todas as telas e
 * o KoraSync com o mesmo número.
 */
class RegrasZaioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'loja.simples.aliquota' => 4.0, 'loja.simples.desde' => '2026-09-01',
            'loja.zaio.canais' => ['shopee', 'mercado_livre', 'tiktok_shop'],
            'loja.zaio.comissao' => 2.0, 'loja.zaio.desde' => '2026-11-01',
            'loja.insumos_por_pedido' => 0.0,
        ]);
        Carbon::setTestNow('2026-11-27 15:00:00');
    }

    private function pedido(string $origem, float $subtotal, float $custo = 40, ?string $quando = null): Order
    {
        $produto = Product::factory()->create(['price' => $subtotal, 'cost_price' => $custo, 'stock' => 10]);
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => $origem,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $subtotal, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => $subtotal,
        ]);
        $pedido->items()->create(['product_id' => $produto->id, 'product_name' => 'Item', 'product_price' => $subtotal, 'quantity' => 1, 'subtotal' => $subtotal, 'item_type' => OrderItem::TYPE_PRODUCT]);
        if ($quando) {
            $pedido->forceFill(['created_at' => $quando])->save();
        }

        return $pedido;
    }

    public function test_shopee_ml_e_tiktok_pagam_comissao_zaio_e_imposto_do_simples(): void
    {
        $this->pedido('shopee', 200, 80);
        $this->pedido('mercado_livre', 100, 40);
        $this->pedido('tiktok_shop', 50, 20);
        $this->pedido('amazon', 100, 40);

        $margem = app(ContributionMargin::class);
        $canal = $margem->porCanal(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-28'));

        $this->assertEqualsWithDelta(8.0, $canal['shopee']['imposto'], 0.001);
        $this->assertEqualsWithDelta(4.0, $canal['shopee']['comissao_zaio'], 0.001);
        // 200 − 80 − 4 de comissão = 116 de margem; − 8 de imposto (4%) = 108.
        $this->assertEqualsWithDelta(116.0, $canal['shopee']['margem'], 0.001);
        $this->assertEqualsWithDelta(108.0, $canal['shopee']['lucro'], 0.001);
        $this->assertEqualsWithDelta(4.0, $canal['mercado_livre']['imposto'], 0.001);
        $this->assertEqualsWithDelta(2.0, $canal['mercado_livre']['comissao_zaio'], 0.001);
        $this->assertEqualsWithDelta(2.0, $canal['tiktok_shop']['imposto'], 0.001);
        $this->assertEqualsWithDelta(1.0, $canal['tiktok_shop']['comissao_zaio'], 0.001);
        // Amazon é caso à parte: sem comissão.
        $this->assertEqualsWithDelta(4.0, $canal['amazon']['imposto'], 0.001);
        $this->assertEqualsWithDelta(0.0, $canal['amazon']['comissao_zaio'], 0.001);

        $total = $margem->periodo(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-28'));
        $this->assertEqualsWithDelta(7.0, $total['comissao_zaio'], 0.001);
        $this->assertEqualsWithDelta(18.0, $total['imposto'], 0.001);
        $this->assertEqualsWithDelta($total['lucro'], array_sum(array_column($canal, 'lucro')), 0.001, 'soma dos canais = total');
    }

    public function test_antes_do_corte_nao_tem_comissao(): void
    {
        $this->pedido('shopee', 100, 40, '2026-10-20 10:00:00');
        $this->pedido('shopee', 100, 40);

        $canal = app(ContributionMargin::class)->porCanal(Carbon::parse('2026-10-01'), Carbon::parse('2026-11-28'));

        // Outubro sem comissão; novembro com 2%. Imposto 4% nos dois.
        $this->assertEqualsWithDelta(8.0, $canal['shopee']['imposto'], 0.001);
        $this->assertEqualsWithDelta(2.0, $canal['shopee']['comissao_zaio'], 0.001);
    }

    public function test_site_fica_no_simples_sem_comissao(): void
    {
        $this->pedido('store', 100, 40);

        $conta = app(ContributionMargin::class)->periodo(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-28'));

        $this->assertEqualsWithDelta(4.0, $conta['imposto'], 0.001);
        $this->assertEqualsWithDelta(0.0, $conta['comissao_zaio'], 0.001);
    }

    public function test_lucro_por_pedido_e_por_item_batem_com_a_conta_do_canal(): void
    {
        $this->pedido('shopee', 200, 80);

        $desde = Carbon::parse('2026-11-01');
        $ate = Carbon::parse('2026-11-28');
        $canal = app(ContributionMargin::class)->porCanal($desde, $ate)['shopee'];

        $linhas = app(LucroPorPedido::class)->ultimos($desde, $ate, ['shopee']);
        $this->assertEqualsWithDelta(4.0, $linhas[0]['conta']['comissao_zaio'], 0.001);
        $this->assertEqualsWithDelta(8.0, $linhas[0]['conta']['imposto'], 0.001);
        // Sem taxa registrada o pedido usa a taxa estimada; fora ela, a conta é a mesma.
        $this->assertEqualsWithDelta($canal['lucro'], $linhas[0]['lucro'] + $linhas[0]['conta']['taxa'], 0.001);

        $itens = app(ContributionMargin::class)->porItem($desde, $ate, 'shopee');
        $this->assertEqualsWithDelta($canal['margem'], array_sum(array_column($itens['linhas'], 'margem')), 0.001);
    }

    public function test_korasync_financeiro_e_email_mostram_o_mesmo_numero_dos_marketplaces(): void
    {
        $this->pedido('shopee', 150, 50);
        $this->pedido('mercado_livre', 80, 30);
        // Site fica fora do KoraSync e do Financeiro › Marketplaces.
        $this->pedido('store', 500, 100);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $margem = app(ContributionMargin::class);
        $mkt = $margem->conta(Carbon::today()->startOfMonth(), Carbon::tomorrow(), ['shopee', 'mercado_livre', 'tiktok_shop', 'amazon', 'shein']);
        $hoje = $margem->conta(Carbon::today(), Carbon::tomorrow(), ['shopee', 'mercado_livre', 'tiktok_shop', 'amazon', 'shein']);

        $this->actingAs($admin)->get('/admin/dashboard-financeiro')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.grossRevenueMonth', fn ($v) => abs($v - $mkt['receita']) < 0.001)
            ->where('summary.profitMonth', fn ($v) => abs($v - $mkt['lucro']) < 0.001)
            ->where('netProfit.zaioCommissionMonth', fn ($v) => abs($v - $mkt['comissao_zaio']) < 0.001)
            // Quadro Zaio: o valor a pagar por canal.
            ->where('zaio.mesAtual.comissao', fn ($v) => abs($v - 4.6) < 0.001)
            ->where('zaio.mesAtual.canais.shopee.comissao', fn ($v) => abs($v - 3.0) < 0.001)
            ->where('zaio.mesAtual.canais.mercado_livre.receita', fn ($v) => abs($v - 80.0) < 0.001)
            ->where('zaio.mesAtual.canais.tiktok_shop.pedidos', 0)
            ->where('zaio.mesAnterior.comissao', fn ($v) => abs($v) < 0.001));

        $metricas = $this->actingAs($admin)->getJson('/admin/korasync-api/metrics')->assertOk()->json();
        $this->assertEqualsWithDelta($mkt['receita'], $metricas['revenue_month'], 0.001);
        $this->assertEqualsWithDelta($hoje['lucro'], $metricas['net_profit_today'], 0.001);

        // 230 − 80 − 4,60 de comissão − 9,20 de imposto = 136,20.
        $this->assertEqualsWithDelta(136.2, $mkt['lucro'], 0.001);
        $email = app(ResultadoDiario::class)->calcular(Carbon::today());
        $this->assertEqualsWithDelta(4.6, $email['grupos']['mes']['plataformas']['total']['comissao_zaio'], 0.001);
        $this->assertEqualsWithDelta($mkt['lucro'], $email['grupos']['mes']['plataformas']['total']['lucro'], 0.001);
    }
}

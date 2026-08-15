<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-09/10/14: lucro líquido de vendas = receita −
 * custo de produto − taxa de marketplace − gasto com anúncio − custo Flex.
 * A taxa de marketplace ficou de fora da conta entre 2026-08-10 e
 * 2026-08-14 (pedido explícito da época: "o lucro liquido é realmente só o
 * que sobra do desconto do material, do ads") — reconsiderado em
 * 2026-08-14 (achado real auditando o dashboard: 57 pedidos Shopee sem
 * taxa nenhuma registrada inflavam o número) e voltou a entrar, porque é
 * um custo real (~12-20% da receita).
 *
 * Valores de teste usam centavos fracionários de propósito (nunca um total
 * "redondo" tipo 100.0) — Inertia serializa um PHP float sem casas
 * decimais como inteiro no JSON (json_encode(100.0) === "100"), e a
 * comparação ->where() do teste é estrita (===): comparar contra o literal
 * 100.0 quebraria com "100 is identical to 100.0" mesmo com a conta certa.
 */
class FinancialDashboardNetProfitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $attributes));
    }

    public function test_net_profit_subtracts_product_cost_and_ad_spend_from_revenue(): void
    {
        $product = Product::factory()->create(['price' => 60.375, 'cost_price' => 40.25]);

        $order = $this->makeOrder(['subtotal' => 120.75, 'total' => 120.75]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 60.375,
            'quantity' => 2, // custo total = 2 * 40.25 = 80.50
            'subtotal' => 120.75,
        ]);

        OrderChannelFee::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'gross_amount' => 120.75,
            'fee_amount' => 15.30,
            'computed_at' => now(),
        ]);

        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'shopee', 'spend' => 10.20]);
        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'mercado_livre', 'spend' => 5.10]);

        $response = $this->actingAs($this->admin())->get('/admin/dashboard-financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('netProfit.salesRevenueMonth', 120.75)
            ->where('netProfit.productCostMonth', 80.5)
            // Pedido explícito 2026-08-14: taxa de marketplace agora entra
            // na conta do lucro líquido.
            ->where('netProfit.marketplaceFeeMonth', 15.3)
            ->where('netProfit.adSpendMonth', 15.3)
            ->where('netProfit.netProfitMonth', 9.65) // 120.75 - 80.50 - 15.30 (taxa) - 15.30 (ads)
            // Pedido explícito 2026-08-09: "lucro no mês sendo só o
            // líquido" — o card de resumo usa o mesmo valor calculado
            // aqui, não mais o saldo de fluxo de caixa manual.
            ->where('summary.profitMonth', 9.65)
            // Cards invertidos (pedido explícito 2026-08-09): bruto/líquido
            // desde o primeiro dia — só há esse 1 pedido no teste, então
            // "desde o início" bate com "no mês".
            ->where('summary.grossRevenueAllTime', 120.75)
            ->where('summary.netProfitAllTime', 9.65)
            ->where('summary.grossRevenueMonth', 120.75)
            // Pedido explícito 2026-08-10: faturamento líquido = bruto -
            // ads (sem abater custo do material) — vai no card "Entradas
            // no Mês".
            ->where('summary.netRevenueMonth', 105.45) // 120.75 - 15.30
            // Mercado Livre sempre indisponível hoje (API pede escopo de
            // pagamentos que o app não tem); Shopee null porque não há
            // MarketplaceAccount conectada neste teste.
            ->where('walletBalances.mercado_livre', null)
            ->where('walletBalances.shopee', null));
    }

    /**
     * BUG REAL 2026-08-14: marketplaceFeeMonth filtrava por computed_at
     * (quando a taxa foi gravada no nosso banco) em vez da data do
     * PEDIDO — um backfill retroativo (ver BackfillShopeeOrderFeesCommand)
     * grava computed_at=agora pra pedido de semanas atrás, e com o filtro
     * antigo essa taxa "pulava" pro mês do backfill em vez de ficar no mês
     * real da venda. Aqui simula exatamente isso: pedido de MÊS PASSADO
     * com taxa gravada (computed_at) HOJE.
     */
    public function test_marketplace_fee_is_attributed_to_the_orders_month_not_when_it_was_backfilled(): void
    {
        // Valores fracionários de propósito (ver docblock da classe) —
        // 100.75 - 18.30 = 82.45, nunca um total redondo tipo 100.0/82.0.
        $lastMonthOrder = $this->makeOrder(['subtotal' => 100.75, 'total' => 100.75]);
        $lastMonthOrder->forceFill(['created_at' => now()->subMonthNoOverflow()])->save();

        OrderChannelFee::create([
            'order_id' => $lastMonthOrder->id,
            'channel' => 'shopee',
            'gross_amount' => 100.75,
            // Backfill rodando HOJE pra um pedido do mês passado.
            'fee_amount' => 18.30,
            'computed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/dashboard-financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('netProfit.marketplaceFeeMonth', 0)
            ->where('netProfit.netProfitMonth', 0)
            // Mas continua contando "desde o início" (sem filtro de data):
            // 100.75 de receita - 18.30 de taxa, sem custo de
            // produto/ads/flex nesse pedido.
            ->where('summary.netProfitAllTime', 82.45));
    }

    /**
     * Pedido explícito 2026-08-15 (achado investigando reclamação real do
     * usuário — pedido #305 Shopee: R$44,99 no Seller Center vs R$58,24 no
     * dashboard): frete é informativo (visível, atrelado ao pedido) mas
     * nunca entra em salesRevenueMonth/netProfitMonth — quem cobra/paga o
     * frete é o canal (Shopee Xpress etc.), nunca o vendedor.
     */
    public function test_shipping_cost_is_informational_and_never_affects_revenue_or_profit(): void
    {
        $this->makeOrder(['subtotal' => 44.99, 'shipping_cost' => 13.25, 'total' => 58.24]);

        $response = $this->actingAs($this->admin())->get('/admin/dashboard-financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('netProfit.shippingCostMonth', 13.25)
            ->where('netProfit.salesRevenueMonth', 44.99)
            ->where('netProfit.netProfitMonth', 44.99)
            ->where('summary.grossRevenueMonth', 44.99));
    }

    public function test_stock_value_sums_cost_price_times_stock_across_all_products(): void
    {
        Product::factory()->create(['cost_price' => 10.50, 'stock' => 4]); // 42.00
        Product::factory()->create(['cost_price' => 5.25, 'stock' => 2]); // 10.50
        Product::factory()->create(['cost_price' => null, 'stock' => 100]); // conta como 0, não quebra

        $response = $this->actingAs($this->admin())->get('/admin/dashboard-financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('summary.stockValue', 52.5));
    }

    public function test_net_profit_flags_when_no_active_product_has_a_cost_price_yet(): void
    {
        Product::factory()->create(['is_active' => true, 'cost_price' => null]);
        Product::factory()->create(['is_active' => true, 'cost_price' => null]);

        $response = $this->actingAs($this->admin())->get('/admin/dashboard-financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('netProfit.productsWithCost', 0)
            ->where('netProfit.productsActive', 2)
            ->where('netProfit.productCostMonth', 0));
    }

    public function test_order_items_without_a_linked_product_do_not_break_the_cost_calculation(): void
    {
        // Item de emissão manual de nota (serviço avulso, sem product_id) —
        // não pode quebrar a query de custo nem contar como custo zero
        // indevido pra um produto real.
        $order = $this->makeOrder(['subtotal' => 50, 'total' => 50]);
        $order->items()->create([
            'product_id' => null,
            'product_name' => 'Serviço avulso',
            'product_price' => 50,
            'quantity' => 1,
            'subtotal' => 50,
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/dashboard-financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('netProfit.productCostMonth', 0));
    }
}

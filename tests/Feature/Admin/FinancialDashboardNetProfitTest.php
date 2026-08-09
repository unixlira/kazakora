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
 * Pedido explícito 2026-08-09: lucro líquido de vendas = receita − custo de
 * produto − taxa de marketplace − gasto com anúncio.
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

    public function test_net_profit_subtracts_product_cost_marketplace_fee_and_ad_spend_from_revenue(): void
    {
        $product = Product::factory()->create(['price' => 100, 'cost_price' => 40]);

        $order = $this->makeOrder(['total' => 100]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 100,
            'quantity' => 2, // custo total = 2 * 40 = 80
            'subtotal' => 100,
        ]);

        OrderChannelFee::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'gross_amount' => 100,
            'fee_amount' => 15,
            'computed_at' => now(),
        ]);

        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'shopee', 'spend' => 10]);
        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'mercado_livre', 'spend' => 5]);

        $response = $this->actingAs($this->admin())->get('/admin/dashboard-financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('netProfit.salesRevenueMonth', 100.0)
            ->where('netProfit.productCostMonth', 80.0)
            ->where('netProfit.marketplaceFeeMonth', 15.0)
            ->where('netProfit.adSpendMonth', 15.0)
            ->where('netProfit.netProfitMonth', -10.0)); // 100 - 80 - 15 - 15
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
            ->where('netProfit.productCostMonth', 0.0));
    }

    public function test_order_items_without_a_linked_product_do_not_break_the_cost_calculation(): void
    {
        // Item de emissão manual de nota (serviço avulso, sem product_id) —
        // não pode quebrar a query de custo nem contar como custo zero
        // indevido pra um produto real.
        $order = $this->makeOrder(['total' => 50]);
        $order->items()->create([
            'product_id' => null,
            'product_name' => 'Serviço avulso',
            'product_price' => 50,
            'quantity' => 1,
            'subtotal' => 50,
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/dashboard-financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('netProfit.productCostMonth', 0.0));
    }
}

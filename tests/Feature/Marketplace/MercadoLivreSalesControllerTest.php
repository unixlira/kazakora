<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MercadoLivreSalesControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $origin, float $total): Order
    {
        return Order::create([
            'origin' => $origin,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente '.$total,
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    public function test_it_shows_gross_fee_and_net_for_orders_with_real_fee_data(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->makeOrder(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 100);
        OrderChannelFee::create([
            'order_id' => $order->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'gross_amount' => 100,
            'fee_amount' => 15,
            'source' => OrderChannelFee::SOURCE_API,
            'computed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/vendas');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('orders.0.gross', 100.0)
            ->where('orders.0.fee', 15.0)
            ->where('orders.0.net', 85.0)
            ->where('summary.grossTotal', 100.0)
            ->where('summary.feeTotal', 15.0)
            ->where('summary.netTotal', 85.0)
            ->where('summary.withFeeDataCount', 1));
    }

    public function test_it_falls_back_to_order_total_without_inventing_a_fee_when_there_is_no_fee_row(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeOrder(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 50);

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/vendas');

        $response->assertInertia(fn ($page) => $page
            ->where('orders.0.gross', 50.0)
            ->where('orders.0.fee', null)
            ->where('orders.0.net', null)
            ->where('summary.feeTotal', 0.0)
            ->where('summary.withFeeDataCount', 0));
    }

    public function test_it_does_not_include_orders_from_other_channels(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeOrder(Order::ORIGIN_STORE, 200);

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/vendas');

        $response->assertInertia(fn ($page) => $page->where('summary.count', 0));
    }
}

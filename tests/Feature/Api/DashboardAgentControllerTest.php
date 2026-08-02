<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $attributes = []): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create(array_merge([
            'user_id' => $user->id,
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

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-print-agent-token'];
    }

    public function test_dashboard_endpoints_reject_requests_without_a_valid_token(): void
    {
        $this->getJson('/api/print-agent/dashboard/channels')->assertStatus(401);
        $this->getJson('/api/print-agent/dashboard/metrics')->assertStatus(401);
    }

    public function test_channels_reports_connection_status_and_last_order_per_channel(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123',
        ]);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_DISCONNECTED,
        ]);

        $storeOrder = $this->makeOrder(['origin' => Order::ORIGIN_STORE]);
        $mlOrder = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-1']);

        $printJob = PrintJob::create([
            'order_id' => $mlOrder->id,
            'label_path' => 'labels/ml-1.pdf',
            'status' => PrintJob::STATUS_PRINTED,
            'printed_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/channels');

        $response->assertOk();
        $channels = collect($response->json('channels'))->keyBy('channel');

        $this->assertTrue($channels[Order::ORIGIN_STORE]['connected']);
        $this->assertSame($storeOrder->id, $channels[Order::ORIGIN_STORE]['last_order']['id']);

        $this->assertTrue($channels[Order::ORIGIN_MERCADO_LIVRE]['connected']);
        $this->assertSame($mlOrder->id, $channels[Order::ORIGIN_MERCADO_LIVRE]['last_order']['id']);
        $this->assertSame(1, $channels[Order::ORIGIN_MERCADO_LIVRE]['labels_printed_today']);
        $this->assertNotNull($channels[Order::ORIGIN_MERCADO_LIVRE]['last_label_printed_at']);

        $this->assertFalse($channels[Order::ORIGIN_SHOPEE]['connected']);
        $this->assertNull($channels[Order::ORIGIN_SHOPEE]['last_order']);

        $this->assertFalse($channels[Order::ORIGIN_TIKTOK_SHOP]['connected']);
    }

    public function test_metrics_reports_todays_revenue_sales_cancellations_refunds_and_cart_items(): void
    {
        $this->makeOrder(['status' => Order::STATUS_PAID, 'total' => 150]);
        $this->makeOrder(['status' => Order::STATUS_COMPLETED, 'total' => 50]);
        $this->makeOrder(['status' => Order::STATUS_CANCELLED, 'total' => 30]);
        $this->makeOrder(['status' => Order::STATUS_AWAITING_PAYMENT, 'total' => 999]);

        $refundedOrder = $this->makeOrder(['status' => Order::STATUS_PAID, 'total' => 80]);
        Payment::create([
            'order_id' => $refundedOrder->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'method_type' => Payment::METHOD_CARD,
            'status' => Payment::STATUS_REFUNDED,
            'amount' => 80,
        ]);

        CartSnapshot::create(['session_id' => 'sess-1', 'items_count' => 3, 'total' => 200]);
        CartSnapshot::create(['session_id' => 'sess-2', 'items_count' => 2, 'total' => 90]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/metrics');

        $response->assertOk();
        $response->assertJson([
            'revenue_today' => 280.0,
            'sales_today' => 3,
            'cancelled_today' => 1,
            'refunded_today' => 1,
            'cart_items_count' => 5,
        ]);
    }

    public function test_channel_orders_returns_404_for_an_unknown_channel(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/channels/nao-existe/orders')
            ->assertNotFound();
    }

    public function test_channel_orders_reports_products_fee_and_shipping_method_when_available(): void
    {
        $order = $this->makeOrder([
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-123',
            'shipping_name' => 'Fulano de Tal',
            'total' => 180.80,
        ]);

        $order->items()->create([
            'product_name' => 'Lixeira Inox 12l',
            'product_price' => 180.80,
            'quantity' => 1,
            'subtotal' => 180.80,
        ]);

        OrderChannelFee::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'gross_amount' => 180.80,
            'fee_amount' => 27.12,
            'source' => OrderChannelFee::SOURCE_API,
            'computed_at' => now(),
        ]);

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_method' => ChannelShipment::METHOD_FLEX,
        ]);

        // Pedido de outro canal não deve aparecer na lista do Mercado Livre.
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/channels/mercado_livre/orders');

        $response->assertOk();
        $orders = $response->json('orders');

        $this->assertCount(1, $orders);
        $this->assertSame($order->id, $orders[0]['id']);
        $this->assertSame('ML-123', $orders[0]['external_order_id']);
        $this->assertSame('Fulano de Tal', $orders[0]['customer_name']);
        $this->assertSame('Lixeira Inox 12l', $orders[0]['products'][0]['name']);
        $this->assertSame(180.80, $orders[0]['gross_amount']);
        $this->assertSame(27.12, $orders[0]['fee_amount']);
        $this->assertSame(153.68, $orders[0]['net_amount']);
        $this->assertSame(ChannelShipment::METHOD_FLEX, $orders[0]['shipping_method']);
    }

    public function test_channel_orders_reports_null_fee_when_the_channel_has_no_fee_integration_yet(): void
    {
        $order = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/channels/shopee/orders');

        $response->assertOk();
        $orders = $response->json('orders');

        $this->assertCount(1, $orders);
        $this->assertNull($orders[0]['fee_amount']);
        $this->assertNull($orders[0]['net_amount']);
        $this->assertNull($orders[0]['shipping_method']);
    }
}

<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MercadoLivreFlexControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeFlexShipment(array $orderAttributes = [], ?Carbon $confirmedAt = null): ChannelShipment
    {
        $order = Order::create(array_merge([
            'origin' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_order_id' => 'ML-'.uniqid(),
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua das Flores',
            'shipping_number' => '123',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $orderAttributes));

        $order->items()->create([
            'product_name' => 'Produto Flex',
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        return ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'shipping_method' => 'self_service',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => $confirmedAt ?? now(),
        ]);
    }

    public function test_index_lists_current_month_flex_deliveries_with_customer_address_and_product(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeFlexShipment();

        // Shopee via self_service não é Flex do Mercado Livre — não deve
        // entrar na lista mesmo tendo o mesmo shipping_method.
        $shopeeOrder = Order::create([
            'origin' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Outro cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Y',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 50,
            'total' => 50,
        ]);
        ChannelShipment::create([
            'order_id' => $shopeeOrder->id,
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'shipping_method' => 'self_service',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/flex');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('deliveries', 1)
            ->where('deliveries.0.customerName', 'Cliente Teste')
            ->where('deliveries.0.address', 'Rua das Flores 123 — Centro — São Paulo/SP — 01000-000')
            ->where('deliveries.0.products.0.name', 'Produto Flex')
            ->where('deliveries.0.total', 100.0));
    }

    public function test_index_filters_deliveries_by_month(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeFlexShipment(confirmedAt: Carbon::now()->subMonthNoOverflow());
        $thisMonth = $this->makeFlexShipment();

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/flex');

        $response->assertInertia(fn ($page) => $page
            ->has('deliveries', 1)
            ->where('deliveries.0.id', $thisMonth->id));

        $lastMonthParam = Carbon::now()->subMonthNoOverflow()->format('Y-m');
        $response = $this->actingAs($admin)->get("/admin/integracoes/mercado-livre/flex?mes={$lastMonthParam}");

        $response->assertInertia(fn ($page) => $page->has('deliveries', 1));
    }

    public function test_index_filters_deliveries_by_order_number(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeFlexShipment(['external_order_id' => 'ACHAR-ESTE-123']);
        $this->makeFlexShipment(['external_order_id' => 'OUTRO-PEDIDO']);

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/flex?pedido=ACHAR-ESTE-123');

        $response->assertInertia(fn ($page) => $page
            ->has('deliveries', 1)
            ->where('deliveries.0.externalOrderId', 'ACHAR-ESTE-123'));
    }

    public function test_update_changes_the_cost_per_delivery(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->put('/admin/integracoes/mercado-livre/flex', [
            'cost_per_delivery' => 15.50,
        ]);

        $response->assertRedirect();

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/flex');
        $response->assertInertia(fn ($page) => $page->where('costPerDelivery', 15.5));
    }
}

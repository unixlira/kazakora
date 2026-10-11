<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MercadoLivreShippingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(string $channel, string $method): ChannelShipment
    {
        $order = Order::create([
            'origin' => $channel,
            'status' => Order::STATUS_PAID,
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
        ]);

        return ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => $channel,
            'shipping_method' => $method,
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function test_it_lists_every_shipment_type_with_friendly_labels_and_counts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeShipment(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'self_service');
        $this->makeShipment(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'fulfillment');
        $this->makeShipment(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'drop_off');
        $this->makeShipment(MarketplaceAccount::CHANNEL_SHOPEE, 'drop_off'); // outro canal, não deve entrar

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/envios');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('shipments', 3)
            ->where('totalCount', 3)
            ->where('shipments.0.shippingMethodLabel', fn ($label) => in_array($label, ['Flex', 'Full', 'Coleta / Correios (PAC)'], true)));
    }

    public function test_filtering_by_tipo_only_returns_matching_shipments(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeShipment(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'self_service');
        $this->makeShipment(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'fulfillment');

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/envios?tipo=self_service');

        $response->assertInertia(fn ($page) => $page
            ->has('shipments', 1)
            ->where('shipments.0.shippingMethod', 'self_service')
            ->where('shipments.0.shippingMethodLabel', 'Flex')
            // O total geral (pra alimentar as abas) continua contando os 2,
            // mesmo com o filtro aplicado na tabela.
            ->where('totalCount', 2));
    }
}

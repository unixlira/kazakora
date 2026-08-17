<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-17: processWebhook() só logava o payload — nenhum pedido
 * do Mercado Livre jamais avançava pra shipped/completed via webhook, porque
 * (diferente da Shopee) o status de nível PEDIDO do ML nunca reflete entrega,
 * só o sub-recurso shipment. Achado ao vivo: 18 pedidos reais já entregues
 * há semanas (status="delivered" na API real) continuavam "paid" pra sempre
 * no nosso banco.
 */
class ShipmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);
    }

    private function makeOrderWithShipment(string $externalShipmentId, string $orderStatus = Order::STATUS_PAID): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-1',
            'status' => $orderStatus,
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

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'external_shipment_id' => $externalShipmentId,
            'shipping_method' => 'drop_off',
            'status' => ChannelShipment::STATUS_ERROR,
            'confirmed_at' => now()->subDays(20),
        ]);

        return $order;
    }

    public function test_webhook_advances_the_order_to_shipped_when_the_channel_reports_shipped(): void
    {
        $order = $this->makeOrderWithShipment('999111');
        Http::fake(['https://api.mercadolibre.com/shipments/999111' => Http::response(['status' => 'shipped'])]);

        app(ShipmentService::class)->processWebhook(['resource' => '/shipments/999111']);

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    public function test_webhook_advances_the_order_directly_to_completed_when_the_channel_reports_delivered(): void
    {
        $order = $this->makeOrderWithShipment('999222');
        Http::fake(['https://api.mercadolibre.com/shipments/999222' => Http::response(['status' => 'delivered'])]);

        app(ShipmentService::class)->processWebhook(['resource' => '/shipments/999222']);

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_webhook_ignores_intermediate_statuses_that_do_not_map_to_an_order_status(): void
    {
        $order = $this->makeOrderWithShipment('999333');
        Http::fake(['https://api.mercadolibre.com/shipments/999333' => Http::response(['status' => 'ready_to_ship'])]);

        app(ShipmentService::class)->processWebhook(['resource' => '/shipments/999333']);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_webhook_never_regresses_an_order_that_is_already_further_along(): void
    {
        $order = $this->makeOrderWithShipment('999444', Order::STATUS_COMPLETED);
        Http::fake(['https://api.mercadolibre.com/shipments/999444' => Http::response(['status' => 'shipped'])]);

        app(ShipmentService::class)->processWebhook(['resource' => '/shipments/999444']);

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_webhook_is_a_no_op_when_the_shipment_is_not_known_locally_yet(): void
    {
        // Webhook pode chegar antes de ChannelShippingService::confirm()
        // criar o ChannelShipment local — não é erro, só ainda não é hora.
        Http::fake(['https://api.mercadolibre.com/shipments/*' => Http::response(['status' => 'delivered'])]);

        app(ShipmentService::class)->processWebhook(['resource' => '/shipments/000999']);

        Http::assertNothingSent();
    }

    public function test_webhook_is_a_no_op_when_the_resource_is_unparseable(): void
    {
        Http::fake();

        app(ShipmentService::class)->processWebhook(['resource' => 'garbage']);

        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-29 ("preferir webhook quando disponível, mas
 * manter uma rotina de verificação periódica como garantia"): o Mercado
 * Livre nunca reflete entrega no PEDIDO em si (só no sub-recurso shipment,
 * ver ShipmentService::processWebhook()) — se o webhook (topic=shipments)
 * se perder por qualquer motivo, o pedido ficava "pago" pra sempre. Este
 * comando reconsulta o shipment real de todo pedido ainda pago do Mercado
 * Livre e reaplica o mesmo mapeamento shipped/delivered do webhook.
 */
class PollShipmentStatusesCommandTest extends TestCase
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

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456789',
        ]);
    }

    private function makeOrderWithShipment(string $externalShipmentId, string $orderStatus = Order::STATUS_PAID, ?\Illuminate\Support\Carbon $shipmentCreatedAt = null): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => "ML-{$externalShipmentId}",
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

        $shipment = ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'external_shipment_id' => $externalShipmentId,
            'shipping_method' => 'drop_off',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now()->subDays(2),
        ]);

        if ($shipmentCreatedAt) {
            $shipment->forceFill(['created_at' => $shipmentCreatedAt])->save();
        }

        return $order;
    }

    public function test_advances_a_paid_order_to_shipped_when_the_channel_already_confirmed_pickup(): void
    {
        $order = $this->makeOrderWithShipment('555111');
        Http::fake(['https://api.mercadolibre.com/shipments/555111' => Http::response(['status' => 'shipped'])]);

        $this->artisan('orders:poll-mercadolivre-shipment-status')->assertSuccessful();

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    public function test_advances_a_paid_order_to_completed_when_the_channel_already_confirmed_delivery(): void
    {
        $order = $this->makeOrderWithShipment('555222');
        Http::fake(['https://api.mercadolibre.com/shipments/555222' => Http::response(['status' => 'delivered'])]);

        $this->artisan('orders:poll-mercadolivre-shipment-status')->assertSuccessful();

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_does_not_touch_orders_that_are_no_longer_paid(): void
    {
        // Já enviado por outro caminho (webhook) — não deveria nem consultar
        // a API de novo pra esses, a query já escopa só status=PAID.
        $order = $this->makeOrderWithShipment('555333', Order::STATUS_SHIPPED);
        Http::fake(['https://api.mercadolibre.com/shipments/*' => Http::response(['status' => 'delivered'])]);

        $this->artisan('orders:poll-mercadolivre-shipment-status')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    public function test_ignores_shipments_older_than_60_days_so_it_does_not_poll_forever(): void
    {
        $order = $this->makeOrderWithShipment('555444', Order::STATUS_PAID, now()->subDays(90));
        Http::fake(['https://api.mercadolibre.com/shipments/*' => Http::response(['status' => 'delivered'])]);

        $this->artisan('orders:poll-mercadolivre-shipment-status')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_a_shipment_check_failure_does_not_stop_the_rest_of_the_batch(): void
    {
        $failing = $this->makeOrderWithShipment('555555');
        $working = $this->makeOrderWithShipment('555666');

        Http::fake([
            'https://api.mercadolibre.com/shipments/555555' => Http::response(['message' => 'boom'], 500),
            'https://api.mercadolibre.com/shipments/555666' => Http::response(['status' => 'shipped']),
        ]);

        $this->artisan('orders:poll-mercadolivre-shipment-status')->assertSuccessful();

        $this->assertSame(Order::STATUS_PAID, $failing->fresh()->status);
        $this->assertSame(Order::STATUS_SHIPPED, $working->fresh()->status);
    }
}

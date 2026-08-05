<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\ChannelShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Confirma o gatilho novo (2026-08-05): assim que o frete é confirmado no
 * canal, o retry orientado a evento já dispara na hora, sem esperar
 * webhook nem polling — só pra Mercado Livre (Shopee/TikTok ainda não têm
 * fetchLabel() implementado, disparar lá só geraria falha garantida).
 */
class ChannelShippingServiceLabelDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $origin): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'origin' => $origin,
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
    }

    private function mockDriverConfirmShipping(string $channel, array $result): void
    {
        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('confirmShipping')->once()->andReturn($result);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with($channel)->once()->andReturn($driver);

        $this->app->instance(MarketplaceDriverManager::class, $manager);
    }

    public function test_confirm_dispatches_check_shipment_label_job_for_mercado_livre(): void
    {
        Queue::fake();
        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);
        $this->mockDriverConfirmShipping(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, [
            'external_shipment_id' => 'SHIP-1',
            'shipping_method' => 'self_service',
            'status' => 'confirmed',
        ]);

        $shipment = app(ChannelShippingService::class)->confirm($order);

        Queue::assertPushed(CheckShipmentLabelJob::class, fn (CheckShipmentLabelJob $job) => $job->shipmentId === $shipment->id);
    }

    public function test_confirm_does_not_dispatch_for_other_channels(): void
    {
        Queue::fake();
        $order = $this->makeOrder(MarketplaceAccount::CHANNEL_SHOPEE);
        $this->mockDriverConfirmShipping(MarketplaceAccount::CHANNEL_SHOPEE, [
            'external_shipment_id' => 'SHOPEE-1',
            'shipping_method' => 'drop_off',
            'status' => 'confirmed',
        ]);

        app(ChannelShippingService::class)->confirm($order);

        Queue::assertNotPushed(CheckShipmentLabelJob::class);
    }
}

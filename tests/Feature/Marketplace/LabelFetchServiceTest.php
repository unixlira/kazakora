<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\LabelFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class LabelFetchServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(): ChannelShipment
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-1',
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

        $order->items()->create([
            'product_name' => 'Produto teste',
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        return ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_shipment_id' => 'SHIP-1',
            'shipping_method' => 'self_service',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    private function mockDriver(array $fetchLabelResult): void
    {
        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchLabel')->once()->andReturn($fetchLabelResult);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with(MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->once()->andReturn($driver);

        $this->app->instance(MarketplaceDriverManager::class, $manager);
    }

    public function test_attempt_returns_false_and_changes_nothing_when_label_is_not_ready(): void
    {
        $shipment = $this->makeShipment();
        $this->mockDriver(['ready' => false, 'contents' => null, 'content_type' => null]);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertFalse($ready);
        $this->assertSame(ChannelShipment::STATUS_CONFIRMED, $shipment->fresh()->status);
        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_attempt_downloads_and_registers_the_label_when_ready(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment();
        $this->mockDriver(['ready' => true, 'contents' => 'not-a-real-pdf', 'content_type' => 'application/octet-stream']);

        $ready = app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertTrue($ready);
        $fresh = $shipment->fresh();
        $this->assertSame(ChannelShipment::STATUS_LABEL_READY, $fresh->status);
        $this->assertNotNull($fresh->label_ready_at);
        Storage::disk('local')->assertExists($fresh->label_path);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $shipment->order_id,
            'status' => PrintJob::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $shipment->order_id,
            'step' => 'label_generated',
            'status' => 'success',
        ]);
    }

    public function test_attempt_is_idempotent_and_does_not_duplicate_the_print_job(): void
    {
        Storage::fake('local');
        $shipment = $this->makeShipment();
        PrintJob::create(['order_id' => $shipment->order_id, 'label_path' => 'labels/existing.pdf', 'status' => PrintJob::STATUS_PRINTED]);

        $this->mockDriver(['ready' => true, 'contents' => 'conteudo', 'content_type' => 'application/octet-stream']);

        app(LabelFetchService::class)->attempt($shipment->fresh());

        $this->assertSame(1, PrintJob::where('order_id', $shipment->order_id)->count());
    }
}

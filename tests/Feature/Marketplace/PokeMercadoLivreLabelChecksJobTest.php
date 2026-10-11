<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Jobs\PokeMercadoLivreLabelChecksJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PokeMercadoLivreLabelChecksJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(string $channel, string $status): ChannelShipment
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
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
            'status' => $status,
        ]);
    }

    public function test_it_dispatches_a_check_job_for_every_confirmed_mercado_livre_shipment_without_a_label(): void
    {
        Queue::fake();

        $pending = $this->makeShipment(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, ChannelShipment::STATUS_CONFIRMED);
        $alreadyReady = $this->makeShipment(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, ChannelShipment::STATUS_LABEL_READY);
        $otherChannel = $this->makeShipment(MarketplaceAccount::CHANNEL_SHOPEE, ChannelShipment::STATUS_CONFIRMED);

        (new PokeMercadoLivreLabelChecksJob())->handle();

        Queue::assertPushed(CheckShipmentLabelJob::class, fn (CheckShipmentLabelJob $job) => $job->shipmentId === $pending->id);
        Queue::assertPushed(CheckShipmentLabelJob::class, 1);
    }

    public function test_it_does_nothing_when_there_is_nothing_pending(): void
    {
        Queue::fake();

        (new PokeMercadoLivreLabelChecksJob())->handle();

        Queue::assertNotPushed(CheckShipmentLabelJob::class);
    }
}

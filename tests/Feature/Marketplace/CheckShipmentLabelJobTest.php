<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\LabelFetchService;
use App\Notifications\LabelUnavailableNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

/**
 * Chama handle()/failed() diretamente, igual GenerateInvoiceJobTest — não
 * dá pra testar o retry de verdade (5s x 4h) via dispatch/worker num teste
 * automatizado, então o que importa validar aqui é o contrato: "não pronta"
 * devolve o job pra fila com release() (pro Laravel refazer a tentativa),
 * "pronta" não devolve, e failed() marca o envio como erro e avisa os
 * admins.
 */
class CheckShipmentLabelJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(string $status = ChannelShipment::STATUS_CONFIRMED): ChannelShipment
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

        return ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_shipment_id' => 'SHIP-1',
            'shipping_method' => 'self_service',
            'status' => $status,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Antes de 2026-09-03 isso era um `throw`, e cada tentativa frustrada
     * virava uma exception registrada no log — a 5s por tentativa, durante
     * horas, isso sozinho gerava logs de centenas de MB por dia. release()
     * reagenda do mesmo jeito, sem passar pelo handler de exceptions.
     */
    public function test_handle_releases_the_job_back_to_the_queue_when_label_is_not_ready(): void
    {
        $shipment = $this->makeShipment();

        $service = Mockery::mock(LabelFetchService::class);
        $service->shouldReceive('attempt')->once()->andReturn(false);

        $job = (new CheckShipmentLabelJob($shipment->id))->withFakeQueueInteractions();
        $job->handle($service);

        $job->assertReleased(delay: 5);
        $job->assertNotFailed();
    }

    public function test_handle_does_not_release_when_label_becomes_ready(): void
    {
        $shipment = $this->makeShipment();

        $service = Mockery::mock(LabelFetchService::class);
        $service->shouldReceive('attempt')->once()->andReturn(true);

        $job = (new CheckShipmentLabelJob($shipment->id))->withFakeQueueInteractions();
        $job->handle($service);

        $job->assertNotReleased();
    }

    public function test_handle_is_a_no_op_when_the_shipment_already_has_a_label(): void
    {
        $shipment = $this->makeShipment(ChannelShipment::STATUS_LABEL_READY);

        $service = Mockery::mock(LabelFetchService::class);
        $service->shouldNotReceive('attempt');

        $job = (new CheckShipmentLabelJob($shipment->id))->withFakeQueueInteractions();
        $job->handle($service);

        $job->assertNotReleased();
    }

    public function test_failed_marks_the_shipment_as_error_and_notifies_admins(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $shipment = $this->makeShipment();

        (new CheckShipmentLabelJob($shipment->id))->failed(new MaxAttemptsExceededException('timeout'));

        $fresh = $shipment->fresh();
        $this->assertSame(ChannelShipment::STATUS_ERROR, $fresh->status);
        $this->assertNotNull($fresh->error_message);

        Notification::assertSentTo($admin, LabelUnavailableNotification::class);
        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $shipment->order_id,
            'step' => 'label_generated',
            'status' => 'failed',
        ]);
    }

    public function test_failed_does_nothing_if_the_label_already_arrived_via_a_concurrent_attempt(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $shipment = $this->makeShipment(ChannelShipment::STATUS_LABEL_READY);

        (new CheckShipmentLabelJob($shipment->id))->failed(new MaxAttemptsExceededException('timeout'));

        $this->assertSame(ChannelShipment::STATUS_LABEL_READY, $shipment->fresh()->status);
        Notification::assertNotSentTo($admin, LabelUnavailableNotification::class);
    }
}

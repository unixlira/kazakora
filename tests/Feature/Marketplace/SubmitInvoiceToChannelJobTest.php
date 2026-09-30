<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Jobs\SubmitInvoiceToChannelJob;
use App\Modules\Marketplace\Support\ChannelInvoiceSubmissionService;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29: o log de produção mostrava "Shipment 48108258060's
 * status is wrong" (ChannelInvoiceSubmissionService) a cada ~2s. Origem: o
 * KoraSync consulta etiqueta-status a cada 2s por até 3 min, e cada consulta
 * enfileirava um SubmitInvoiceToChannelJob NOVO (sem trava de unicidade),
 * cada um com 6 tentativas — dezenas de jobs em paralelo martelando o canal
 * com a mesma recusa.
 */
class SubmitInvoiceToChannelJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $status = Order::STATUS_PAID): Order
    {
        return Order::create([
            'status' => $status,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-'.uniqid(),
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

    public function test_job_is_unique_per_order(): void
    {
        Queue::fake();

        $job = new SubmitInvoiceToChannelJob(42);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('42', $job->uniqueId());

        SubmitInvoiceToChannelJob::dispatch(42);
        SubmitInvoiceToChannelJob::dispatch(42);
        SubmitInvoiceToChannelJob::dispatch(43);

        Queue::assertPushed(SubmitInvoiceToChannelJob::class, 2);
    }

    public function test_cancelled_order_never_submits_the_invoice_to_the_channel(): void
    {
        $order = $this->makeOrder(Order::STATUS_CANCELLED);

        $service = Mockery::mock(ChannelInvoiceSubmissionService::class);
        $service->shouldNotReceive('submit');

        (new SubmitInvoiceToChannelJob($order->id))->handle($service);
        $this->addToAssertionCount(1);
    }

    /**
     * "status is wrong" com o pedido já fora de "pago" (enviado/concluído)
     * é permanente: o envio do canal já andou e nunca vai aceitar a nota.
     * Falha de vez em vez de gastar as 6 tentativas.
     */
    public function test_shipment_status_is_wrong_on_an_order_that_left_paid_fails_without_retry(): void
    {
        $order = $this->makeOrder(Order::STATUS_SHIPPED);

        $service = Mockery::mock(ChannelInvoiceSubmissionService::class);
        $service->shouldReceive('submit')->once()->andThrow(new RuntimeException("Shipment 48108258060's status is wrong"));

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('fail')->once();

        $job = new SubmitInvoiceToChannelJob($order->id);
        $job->setJob($queueJob);
        $job->handle($service);
    }

    /**
     * Pedido ainda pago: a mesma recusa é transitória (envio agendado do ML
     * ainda não liberado) — continua no retry com backoff de sempre.
     */
    public function test_shipment_status_is_wrong_on_a_paid_order_still_retries(): void
    {
        $order = $this->makeOrder(Order::STATUS_PAID);

        $service = Mockery::mock(ChannelInvoiceSubmissionService::class);
        $service->shouldReceive('submit')->once()->andThrow(new RuntimeException("Shipment 48108258060's status is wrong"));

        $this->expectException(RuntimeException::class);

        (new SubmitInvoiceToChannelJob($order->id))->handle($service);
    }
}

<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelInvoiceSubmission;
use Throwable;

/**
 * Etapa 3 do pipeline venda→nota→envio→etiqueta: envia a NF-e autorizada de
 * um pedido de canal externo pro canal correspondente. Canal-agnóstico —
 * delega o formato/endpoint real pro driver (MarketplaceChannelDriver::submitInvoice()).
 */
class ChannelInvoiceSubmissionService
{
    public function __construct(
        private readonly MarketplaceDriverManager $manager,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {
    }

    public function submit(Order $order): ChannelInvoiceSubmission
    {
        $order->loadMissing('invoice');
        $invoice = $order->invoice;

        $submission = ChannelInvoiceSubmission::query()->firstOrCreate(
            ['order_id' => $order->id, 'invoice_id' => $invoice->id, 'channel' => $order->origin],
            ['status' => ChannelInvoiceSubmission::STATUS_PENDING],
        );

        if ($submission->status === ChannelInvoiceSubmission::STATUS_ACCEPTED) {
            return $submission;
        }

        try {
            $result = $this->manager->driver($order->origin)->submitInvoice($order, $invoice);
        } catch (Throwable $exception) {
            $submission->update([
                'status' => ChannelInvoiceSubmission::STATUS_ERROR,
                'error_message' => $exception->getMessage(),
                'submitted_at' => now(),
            ]);

            $this->timeline->record($order, OrderFulfillmentEvent::STEP_INVOICE_SUBMITTED, OrderFulfillmentEvent::STATUS_FAILED, $exception->getMessage());

            throw $exception;
        }

        $sent = $result['status'] === 'sent';

        $submission->update([
            'status' => $sent ? ChannelInvoiceSubmission::STATUS_SENT : ChannelInvoiceSubmission::STATUS_ERROR,
            'external_reference' => $result['external_reference'],
            'response_payload' => $result['response'],
            'error_message' => $sent ? null : ($result['response']['error'] ?? 'Canal rejeitou o envio da nota.'),
            'submitted_at' => now(),
            'responded_at' => now(),
        ]);

        $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_INVOICE_SUBMITTED,
            $sent ? OrderFulfillmentEvent::STATUS_SUCCESS : OrderFulfillmentEvent::STATUS_FAILED,
            $sent ? 'Nota enviada ao canal' : ($result['response']['error'] ?? 'Canal rejeitou o envio da nota.'),
        );

        if ($sent) {
            ConfirmChannelShippingJob::dispatch($order->id)->afterCommit();
        }

        return $submission;
    }
}

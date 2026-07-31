<?php

namespace App\Modules\Fiscal\Jobs;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderReceiptEmailJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\InvoiceGenerationLog;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Notifications\InvoiceIssuanceFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emite a NF-e de um pedido pago, de forma assíncrona (disparado pelo
 * webhook do Stripe/endpoint de status, nunca chamado sincronamente).
 *
 * Retry só se aplica a falha TÉCNICA (conexão, SOAP, certificado, resposta
 * ilegível) — ver InvoiceService::issue(). Uma resposta definitiva da SEFAZ
 * (autorizada/rejeitada/denegada) ou a ausência de certificado configurado
 * não geram exceção, então terminam o job normalmente (sem retry) e já
 * disparam o e-mail de recibo em seguida.
 */
class GenerateInvoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $orderId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(InvoiceService $invoices): void
    {
        $order = Order::findOrFail($this->orderId);

        try {
            $invoice = $invoices->issue($order);

            $errorMessage = match (true) {
                $invoice->status === Invoice::STATUS_AUTHORIZED => null,
                $invoice->status === Invoice::STATUS_PENDING => 'Certificado digital não configurado — emissão pendente.',
                default => $invoice->motivo_rejeicao,
            };

            InvoiceGenerationLog::create([
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'attempt' => $this->attempts(),
                'status' => $invoice->status === Invoice::STATUS_AUTHORIZED
                    ? InvoiceGenerationLog::STATUS_SUCCESS
                    : InvoiceGenerationLog::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable $exception) {
            InvoiceGenerationLog::create([
                'order_id' => $order->id,
                'invoice_id' => $order->fresh()->invoice?->id,
                'attempt' => $this->attempts(),
                'status' => $this->attempts() < $this->tries
                    ? InvoiceGenerationLog::STATUS_RETRYING
                    : InvoiceGenerationLog::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        SendOrderReceiptEmailJob::dispatch($order->id);
    }

    /**
     * Chamado pelo Laravel quando as $tries se esgotam de verdade (falha
     * técnica persistente). O e-mail de recibo sai mesmo assim (sem anexo),
     * e os admins são avisados pra revisar o pedido manualmente.
     */
    public function failed(?Throwable $exception): void
    {
        $order = Order::find($this->orderId);

        if (! $order) {
            return;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new InvoiceIssuanceFailedNotification($order, $exception?->getMessage() ?? 'Erro desconhecido'));
        }

        SendOrderReceiptEmailJob::dispatch($order->id);
    }
}

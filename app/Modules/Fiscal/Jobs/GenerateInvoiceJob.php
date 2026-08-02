<?php

namespace App\Modules\Fiscal\Jobs;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderReceiptEmailJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\InvoiceGenerationLog;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Jobs\SubmitInvoiceToChannelJob;
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

    public function handle(InvoiceService $invoices, OrderFulfillmentTimeline $timeline): void
    {
        $order = Order::findOrFail($this->orderId);

        try {
            $invoice = $invoices->issue($order);

            $isTerminalSuccess = in_array($invoice->status, [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_EXTERNAL], true);

            $errorMessage = match (true) {
                $invoice->status === Invoice::STATUS_AUTHORIZED => null,
                $invoice->status === Invoice::STATUS_EXTERNAL => null,
                $invoice->status === Invoice::STATUS_PENDING => 'Certificado digital não configurado — emissão pendente.',
                default => $invoice->motivo_rejeicao,
            };

            InvoiceGenerationLog::create([
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'attempt' => $this->attempts(),
                'status' => $isTerminalSuccess
                    ? InvoiceGenerationLog::STATUS_SUCCESS
                    : InvoiceGenerationLog::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);

            $timeline->record(
                $order,
                OrderFulfillmentEvent::STEP_INVOICE_ISSUED,
                $isTerminalSuccess ? OrderFulfillmentEvent::STATUS_SUCCESS : OrderFulfillmentEvent::STATUS_FAILED,
                match (true) {
                    $invoice->status === Invoice::STATUS_AUTHORIZED => "NF-e autorizada, chave {$invoice->chave_acesso}",
                    $invoice->status === Invoice::STATUS_EXTERNAL => 'Nota fiscal emitida pelo próprio canal — Kazakora não emite pra evitar duplicidade.',
                    default => $errorMessage,
                },
            );

            // Nota nossa autorizada: dispara a etapa seguinte de verdade
            // (enviar a nota pro canal via API, que é o que libera o envio
            // do lado deles). Nota externa (canal já emitiu a própria):
            // não tem nota nossa pra enviar, mas o envio ainda precisa ser
            // confirmado/consultado — pula direto pra essa etapa, sem
            // passar pela submissão de nota. Pedido do site (origin=loja)
            // não passa por canal nenhum, não tem próxima etapa aqui.
            if ($invoice->status === Invoice::STATUS_AUTHORIZED && $order->origin !== Order::ORIGIN_STORE) {
                SubmitInvoiceToChannelJob::dispatch($order->id)->afterCommit();
            } elseif ($invoice->status === Invoice::STATUS_EXTERNAL) {
                ConfirmChannelShippingJob::dispatch($order->id)->afterCommit();
            }
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

            if ($this->attempts() >= $this->tries) {
                $timeline->record($order, OrderFulfillmentEvent::STEP_INVOICE_ISSUED, OrderFulfillmentEvent::STATUS_FAILED, $exception->getMessage());
            }

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

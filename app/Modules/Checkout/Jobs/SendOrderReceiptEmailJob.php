<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderEmailLog;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Envia o e-mail de recibo do pedido — sempre, independente do resultado da
 * emissão da NF-e (com anexo se a nota já estiver autorizada, sem anexo caso
 * contrário). É o único lugar que efetivamente chama Mail::send(); a
 * OrderConfirmation não é mais ShouldQueue, então o envio acontece dentro do
 * retry/timeout deste job, não escondido num segundo job separado.
 */
class SendOrderReceiptEmailJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

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
        return [30, 120, 300];
    }

    public function handle(): void
    {
        $order = Order::with(['invoice', 'user'])->findOrFail($this->orderId);

        if (OrderEmailLog::query()->where('order_id', $order->id)->where('status', OrderEmailLog::STATUS_SENT)->exists()) {
            // Idempotente: esse pedido já teve o recibo enviado com sucesso.
            return;
        }

        $invoiceAttached = $order->invoice?->status === Invoice::STATUS_AUTHORIZED
            && $order->invoice->danfe_path
            && Storage::disk('local')->exists($order->invoice->danfe_path);

        // Achado real 2026-08-08 (pedido #189, mesma investigação da
        // correção de CPF): pedido de marketplace nunca tem Order::user
        // (user_id fica null nesse fluxo) — Mail::to($order->user) mandava
        // "null" pro Symfony, que rejeita com "must have a To/Cc/Bcc
        // header". Isso derrubava o e-mail de recibo de TODO pedido
        // Shopee (ela nunca manda buyer_email — ver
        // ShopeeDriver::importOrder()), sempre esgotando as 3 tentativas
        // sem nunca poder dar certo, só poluindo failed_jobs. Usa
        // shipping_email (é onde o e-mail do canal, quando existe, já é
        // gravado — ver OrderImportService::createOrder()) como
        // alternativa a um user local.
        $recipient = $order->user?->email ?: $order->shipping_email;

        if (! $recipient) {
            OrderEmailLog::create([
                'order_id' => $order->id,
                'mailable' => 'order_confirmation',
                'attempt' => $this->attempts(),
                'status' => OrderEmailLog::STATUS_SKIPPED,
                'invoice_attached' => $invoiceAttached,
                'error_message' => 'Pedido sem e-mail de comprador disponível (canal não forneceu) — recibo não enviado, não é uma falha pra tentar de novo.',
            ]);

            return;
        }

        try {
            Mail::to($recipient)->send(new OrderConfirmation($order));

            OrderEmailLog::create([
                'order_id' => $order->id,
                'mailable' => 'order_confirmation',
                'attempt' => $this->attempts(),
                'status' => OrderEmailLog::STATUS_SENT,
                'invoice_attached' => $invoiceAttached,
            ]);
        } catch (Throwable $exception) {
            OrderEmailLog::create([
                'order_id' => $order->id,
                'mailable' => 'order_confirmation',
                'attempt' => $this->attempts(),
                'status' => OrderEmailLog::STATUS_FAILED,
                'invoice_attached' => $invoiceAttached,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

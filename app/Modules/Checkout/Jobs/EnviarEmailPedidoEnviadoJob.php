<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Mail\PedidoEnviado;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderEmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail "Seu pedido foi enviado" (2026-10-19): disparado por
 * loja:acompanhar-rastreio quando os Correios registram a postagem.
 *
 * Idempotente: se o order_email_logs já tem esse e-mail enviado, não manda
 * de novo. Cada tentativa fica registrada lá com o número dela.
 */
class EnviarEmailPedidoEnviadoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $orderId, public readonly ?string $previsao = null)
    {
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $order = Order::with(['user', 'latestCorreiosPrePostagem', 'channelShipment'])->find($this->orderId);

        if (! $order || $order->origin !== Order::ORIGIN_STORE || $order->status === Order::STATUS_CANCELLED) {
            return;
        }

        $jaEnviado = OrderEmailLog::query()
            ->where('order_id', $order->id)
            ->where('mailable', PedidoEnviado::class)
            ->where('status', OrderEmailLog::STATUS_SENT)
            ->exists();
        $destinatario = $order->user?->email ?: ($order->shipping_email ?: null);

        if ($jaEnviado || $destinatario === null) {
            return;
        }

        try {
            Mail::to($destinatario)->send(new PedidoEnviado($order, SendOrderShippedWhatsAppJob::previsao($this->previsao)));
        } catch (Throwable $exception) {
            Log::warning('pedido.enviado.email_failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            OrderEmailLog::create([
                'order_id' => $order->id,
                'mailable' => PedidoEnviado::class,
                'attempt' => $this->attempts(),
                'status' => OrderEmailLog::STATUS_FAILED,
                'invoice_attached' => false,
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            throw $exception;
        }

        OrderEmailLog::create([
            'order_id' => $order->id,
            'mailable' => PedidoEnviado::class,
            'attempt' => $this->attempts(),
            'status' => OrderEmailLog::STATUS_SENT,
            'invoice_attached' => false,
        ]);
    }
}

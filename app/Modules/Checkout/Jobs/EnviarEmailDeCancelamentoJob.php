<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\CancelamentoDoPedido;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * E-mail "Seu pedido foi cancelado" do cancelamento pelo cliente (Lira,
 * 2026-10-18: em fila, com novas tentativas se falhar). Roda depois do
 * EstornarPedidoCanceladoJob para dizer quanto foi estornado.
 *
 * Idempotente: se o order_email_logs já tem esse e-mail enviado, não manda
 * de novo. Cada tentativa fica registrada lá com o número dela.
 */
class EnviarEmailDeCancelamentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 60;

    public function __construct(public readonly int $orderId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(CancelamentoDoPedido $cancelamento): void
    {
        $order = Order::with('user')->find($this->orderId);

        if (! $order || $order->status !== Order::STATUS_CANCELLED || $order->origin !== Order::ORIGIN_STORE) {
            return;
        }

        if ($cancelamento->jaMandouEmailDeCancelamento($order)) {
            return;
        }

        $email = $cancelamento->avisarCliente($order, $cancelamento->valorEstornadoNoCancelamento($order), $this->attempts());

        if (! $email['ok']) {
            throw new RuntimeException($email['texto']);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($order = Order::find($this->orderId)) {
            app(CancelamentoDoPedido::class)->avisarAdmins($order, [
                "O e-mail de cancelamento para o cliente não saiu depois de {$this->tries} tentativas: avise por outro meio.",
            ]);
        }
    }
}

<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\CancelamentoDoPedido;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancelamento pedido pelo cliente em Meus pedidos, em segundo plano
 * (Lira, 2026-10-18): o Enviar do modal só marca o
 * pedido como cancelado e responde na hora; aqui saem a NF-e na SEFAZ e a
 * devolução do estoque. Estorno e e-mail têm jobs próprios, com novas
 * tentativas (EstornarPedidoCanceladoJob e EnviarEmailDeCancelamentoJob).
 *
 * Fila "nfe": o worker dela já carrega o certificado e tem 300s de prazo,
 * e a SEFAZ lenta não segura e-mail nem etiqueta da fila "default".
 *
 * Uma tentativa só: cancelamento de nota não se repete às cegas. Se quebrar no meio, os admins recebem o aviso no sino para
 * conferir na tela do pedido o que já foi feito.
 */
class ProcessarCancelamentoDoPedidoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    /** @param list<string> $avisosDoPacote etiqueta impressa ou pré-postagem, lidos antes de cancelar */
    public function __construct(
        public readonly int $orderId,
        public readonly string $statusAnterior,
        public readonly array $avisosDoPacote = [],
    )
    {
        $this->onQueue('nfe');
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(CancelamentoDoPedido $cancelamento): void
    {
        $order = Order::find($this->orderId);

        if (! $order || $order->status !== Order::STATUS_CANCELLED) {
            return;
        }

        $resultado = $cancelamento->processarPeloCliente($order, $this->statusAnterior, $this->avisosDoPacote);

        Log::info('pedido.cancelado_pelo_cliente.processado', ['order_id' => $order->id, 'etapas' => $resultado['etapas']]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('pedido.cancelado_pelo_cliente.falhou', ['order_id' => $this->orderId, 'message' => $exception->getMessage()]);

        if ($order = Order::find($this->orderId)) {
            app(CancelamentoDoPedido::class)->avisarAdmins($order, [
                ...$this->avisosDoPacote,
                "O processamento automático parou no meio ({$exception->getMessage()}). Confira na tela do pedido se a NF-e foi cancelada e se o estoque voltou.",
            ]);
        }
    }
}

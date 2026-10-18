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
use RuntimeException;
use Throwable;

/**
 * Estorno do pedido que o cliente cancelou em Meus pedidos (Lira,
 * 2026-10-18: estorno e e-mail em fila, com novas tentativas se falhar).
 * Entra na fila no mesmo instante do Enviar, encadeado com o
 * EnviarEmailDeCancelamentoJob (ver CancelamentoDoPedido::solicitarPeloCliente).
 *
 * Tentar de novo é seguro: CancelamentoDoPedido::estornar() só mexe em
 * pagamento ainda capturado, autorizado ou boleto pendente (o que já foi
 * estornado fica de fora) e o estorno vai com chave fixa por pagamento,
 * então uma resposta perdida do Mercado Pago não vira estorno duplo.
 */
class EstornarPedidoCanceladoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

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
        return 7200;
    }

    /** Mercado Pago fora do ar costuma voltar em minutos: 1, 5, 15 e 30 min. */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(CancelamentoDoPedido $cancelamento): void
    {
        $order = Order::find($this->orderId);

        if (! $order || $order->status !== Order::STATUS_CANCELLED) {
            return;
        }

        $resultado = $cancelamento->estornar($order);

        if (! $resultado['ok']) {
            throw new RuntimeException($resultado['aviso'] ?? $resultado['texto']);
        }

        Log::info('pedido.cancelado_pelo_cliente.estorno', ['order_id' => $order->id, 'tentativa' => $this->attempts(), 'texto' => $resultado['texto']]);
    }

    /**
     * Esgotou as tentativas: o admin faz o estorno pelo painel e o e-mail
     * sai mesmo assim (a corrente parou aqui, então ele é disparado à mão).
     */
    public function failed(Throwable $exception): void
    {
        Log::error('pedido.cancelado_pelo_cliente.estorno_falhou', ['order_id' => $this->orderId, 'message' => $exception->getMessage()]);

        if ($order = Order::find($this->orderId)) {
            app(CancelamentoDoPedido::class)->avisarAdmins($order, [
                "O estorno não saiu depois de {$this->tries} tentativas ({$exception->getMessage()}). Faça o estorno pelo painel do pagamento.",
            ]);
        }

        EnviarEmailDeCancelamentoJob::dispatch($this->orderId);
    }
}

<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\MelhorEnvioAutoShipping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Transportadora do Melhor Envio escolhida no momento do pedido (pedido do
 * Lira em 2026-11-03: "quando o cara faz o pedido, já saiu o pedido com
 * aquela transportadora"). A mais barata para o CEP e o pacote do pedido
 * (TURBO: a mais rápida) fica gravada em melhor_envio_envios e a etiqueta
 * sai com ela. Com os Correios conectados não faz nada.
 *
 * Falhou (API fora): a etiqueta cota na hora de gerar, como antes.
 */
class CotarFreteDoPedidoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $orderId)
    {
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(MelhorEnvioAutoShipping $melhorEnvio): void
    {
        $order = Order::query()->with('items')->find($this->orderId);

        if ($order) {
            $melhorEnvio->escolherNoPedido($order);
        }
    }
}

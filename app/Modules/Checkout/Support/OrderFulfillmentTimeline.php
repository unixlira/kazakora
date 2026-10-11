<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;

/**
 * Ponto único de escrita da timeline "venda → nota → envio → etiqueta →
 * impressão" que aparece no admin. Cada etapa do pipeline (importação de
 * pedido, emissão de NF-e, envio ao canal, confirmação de frete, etiqueta,
 * impressão) grava aqui, sempre pelo mesmo formato — histórico fica
 * consistente entre canais sem cada um reinventar o próprio log.
 */
class OrderFulfillmentTimeline
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(Order $order, string $step, string $status, ?string $message = null, array $context = []): OrderFulfillmentEvent
    {
        // Mesma etapa com o mesmo resultado e a mesma mensagem do último
        // registro dela = repetição (webhook reentregue, consulta que falha
        // igual a cada minuto). Soma no contador em vez de criar linha nova:
        // foi assim que o histórico chegou a milhares de linhas iguais.
        $ultimo = OrderFulfillmentEvent::query()
            ->where('order_id', $order->id)
            ->where('step', $step)
            ->latest('id')
            ->first();

        if ($ultimo && $ultimo->status === $status && $ultimo->message === $message) {
            $ultimo->repeticoes++;
            $ultimo->context = $context ?: $ultimo->context;
            $ultimo->save();

            return $ultimo;
        }

        return OrderFulfillmentEvent::create([
            'order_id' => $order->id,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'context' => $context,
        ]);
    }
}

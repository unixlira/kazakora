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
        return OrderFulfillmentEvent::create([
            'order_id' => $order->id,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'context' => $context,
        ]);
    }
}

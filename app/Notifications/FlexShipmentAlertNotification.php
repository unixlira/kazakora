<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * Alerta novo num envio Flex (FlexControlService::reavaliar()): venda
 * cancelada com o produto fora da loja, devolução, entregador que não
 * iniciou a rota. Uma notificação por alerta, uma vez só — o controle de
 * "já avisei" fica em channel_shipments.flex_alerts_notified.
 */
class FlexShipmentAlertNotification extends Notification
{
    public function __construct(
        private readonly Order $order,
        private readonly string $titulo,
        private readonly string $detalhe,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'message' => "Envio Flex #{$this->order->id}: {$this->titulo}",
            'body' => $this->detalhe,
            'link' => '/admin/envios-flex?busca='.$this->order->id,
        ];
    }
}

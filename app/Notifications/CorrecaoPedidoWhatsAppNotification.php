<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * Cliente tocou em "Corrigir Informações" na confirmação do pedido pelo
 * WhatsApp: alguém precisa ajustar os dados antes da etiqueta.
 */
class CorrecaoPedidoWhatsAppNotification extends Notification
{
    public function __construct(private readonly Order $order)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'message' => "Pedido #{$this->order->numero}: o cliente pediu para corrigir nome, e-mail ou endereço pelo WhatsApp. Confira antes da etiqueta.",
        ];
    }
}

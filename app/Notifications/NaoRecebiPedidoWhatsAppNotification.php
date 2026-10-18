<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * Cliente tocou em "Não Recebi" no aviso de entrega pelo WhatsApp: alguém
 * precisa falar com ele e verificar a entrega com os Correios.
 */
class NaoRecebiPedidoWhatsAppNotification extends Notification
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
            'message' => "Pedido #{$this->order->numero}: os Correios marcaram como entregue, mas o cliente disse pelo WhatsApp que não recebeu. Fale com ele e abra a reclamação nos Correios.",
        ];
    }
}

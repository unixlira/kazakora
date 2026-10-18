<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * Correios marcaram "entregue" numa cidade diferente da do pedido: quase
 * sempre é o pacote voltando para a loja. O pedido não é concluído e o
 * cliente não recebe o aviso de entrega até alguém conferir.
 */
class EntregaForaDoDestinoNotification extends Notification
{
    public function __construct(private readonly Order $order, private readonly string $cidade)
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
            'message' => "Pedido #{$this->order->numero}: os Correios marcaram como entregue em {$this->cidade}, mas o destino é {$this->order->shipping_city}. Provável devolução: confira o pacote e fale com o cliente.",
        ];
    }
}

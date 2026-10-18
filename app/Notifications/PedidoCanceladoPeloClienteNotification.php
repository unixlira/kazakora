<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * O cliente cancelou a compra pelo botão de Meus pedidos (2026-10-17).
 * Avisa os admins no sino com o motivo e o que não deu certo sozinho
 * (nota fora das 24h, estorno que falhou) para tratar na tela do pedido.
 */
class PedidoCanceladoPeloClienteNotification extends Notification
{
    /** @param list<string> $avisos */
    public function __construct(
        private readonly Order $order,
        private readonly array $avisos = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $pendencias = $this->avisos === [] ? '' : ' Para tratar: '.implode(' ', $this->avisos);

        return [
            'order_id' => $this->order->id,
            'message' => "Cliente cancelou o pedido #{$this->order->id}",
            'body' => "Motivo: {$this->order->motivo_cancelamento}.{$pendencias}",
            'link' => "/admin/pedidos/{$this->order->id}",
            'acao' => 'Ver pedido',
            'icone' => 'fa-circle-xmark',
        ];
    }
}

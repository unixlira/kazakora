<?php

namespace App\Notifications\Cliente;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

/** Pagamento aprovado na loja (pedido 2026-10-11): avisa no sino e leva à compra. */
class PedidoConfirmado extends Notification
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
        $total = number_format((float) $this->order->total, 2, ',', '.');

        return [
            'order_id' => $this->order->id,
            'message' => "Pedido {$this->order->numero} confirmado! 🎉",
            'body' => "Recebemos o pagamento de R$ {$total}. Já estamos separando a sua compra; a nota fiscal e o código de rastreio aparecem no detalhe do pedido assim que saírem.",
            'link' => "/pedidos/{$this->order->id}",
            'acao' => 'Ver minha compra',
            'icone' => 'fa-bag-shopping',
        ];
    }
}

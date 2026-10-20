<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use Illuminate\Notifications\Notification;

/**
 * Pix ou boleto aprovado no Mercado Pago depois de o pedido já ter sido
 * cancelado (varredura, admin ou cliente). O pedido não volta sozinho (o
 * estoque já voltou) e o dinheiro não é devolvido sozinho: antes ficava só
 * no log, e ninguém via. Agora o sino do admin avisa para decidir entre
 * estornar ou refazer o pedido.
 */
class PagamentoEmPedidoCanceladoNotification extends Notification
{
    public function __construct(
        private readonly Order $order,
        private readonly Payment $payment,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $valor = 'R$ '.number_format((float) $this->payment->amount, 2, ',', '.');
        $meio = $this->payment->method_type === Payment::METHOD_BOLETO ? 'Boleto' : 'Pix';

        return [
            'order_id' => $this->order->id,
            'message' => "{$meio} de {$valor} pago no pedido #{$this->order->id}, que já estava cancelado",
            'body' => "O Mercado Pago aprovou o pagamento {$this->payment->mercadopago_payment_id} ({$meio}, {$valor}) depois de o pedido #{$this->order->id} ser cancelado. "
                .'O pedido não foi reaberto e o dinheiro não foi devolvido: estorne pelo pedido (Reenviar estorno) ou refaça a venda com o cliente.',
        ];
    }
}

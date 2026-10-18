<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Checkout\Models\Order;
use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;

/**
 * "Seu pedido foi cancelado" (2026-10-17): sai quando o admin cancela (botão
 * ou troca de status) e quando o cliente cancela em Meus pedidos.
 * Só pedido do site: comprador de marketplace é avisado pelo próprio canal.
 * O motivo digitado é interno e não vai no e-mail.
 */
class PedidoCancelado extends Mailable
{
    public function __construct(
        public readonly Order $order,
        public readonly float $valorEstornado = 0.0,
    ) {
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::doPedido($this->order);

        return $this
            // Remetente com o nome do site só no FerraKora; KazaKora segue o MAIL_FROM_NAME.
            ->when($this->order->site_origem === Order::SITE_FERRAKORA, fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->subject("Seu pedido {$this->order->numero} foi cancelado")
            ->view('emails.pedido-cancelado', ['marcaEmail' => $marca]);
    }
}

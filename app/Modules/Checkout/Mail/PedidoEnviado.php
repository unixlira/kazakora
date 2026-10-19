<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Checkout\Models\Order;
use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;

/**
 * "Seu pedido foi enviado" (2026-10-19, pedido do Lira): sai junto com o
 * WhatsApp "Seu pedido saiu para entrega" quando o rastreio dos Correios
 * registra a postagem (loja:acompanhar-rastreio). Só pedido do site.
 */
class PedidoEnviado extends Mailable
{
    public function __construct(
        public readonly Order $order,
        public readonly string $previsao,
    ) {
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::doPedido($this->order);
        // Rastreio público só existe no domínio da KazaKora; FerraKora leva ao "Meus pedidos" de lá.
        $link = $this->order->site_origem === Order::SITE_FERRAKORA
            ? $marca['pedido']
            : route('rastreio.ver', $this->order->trackingRef());

        return $this
            ->when($this->order->site_origem === Order::SITE_FERRAKORA, fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->subject("Seu pedido {$this->order->numero} foi enviado")
            ->view('emails.pedido-enviado', [
                'marcaEmail' => $marca,
                'linkRastreio' => $link,
                'codigoRastreio' => $this->order->trackingCode(),
            ]);
    }
}

<?php

namespace App\Modules\Checkout\Mail;

use App\Modules\Checkout\Models\Order;
use App\Support\MarcaDoEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * "Seu Pix está pronto" (pedido 2026-10-11): o Mercado Pago recebe um e-mail
 * nosso no Pix (config loja.email_pagador_pix) e não escreve ao cliente —
 * quem manda o QR Code e o copia-e-cola é a KazaKora, com a nossa marca.
 */
class PixAguardandoPagamento extends Mailable
{
    public function __construct(
        public readonly Order $order,
        public readonly float $valor,
        public readonly string $copiaECola,
        public readonly ?string $qrCodeBase64 = null,
        public readonly ?string $expiraEm = null,
    ) {
    }

    public function build(): self
    {
        $marca = MarcaDoEmail::doPedido($this->order);

        return $this
            // Remetente com o nome do site só no FerraKora; KazaKora segue o MAIL_FROM_NAME.
            ->when($this->order->site_origem === Order::SITE_FERRAKORA, fn (self $mail) => $mail->from((string) config('mail.from.address'), $marca['nome']))
            ->subject("Seu Pix do pedido {$this->order->numero} está pronto")
            ->view('emails.pix-aguardando-pagamento', [
                'marcaEmail' => $marca,
                'qrPng' => $this->qrCodeBase64 ? base64_decode($this->qrCodeBase64) : null,
                'validade' => $this->expiraEm ? Carbon::parse($this->expiraEm)->timezone('America/Sao_Paulo') : null,
            ]);
    }
}

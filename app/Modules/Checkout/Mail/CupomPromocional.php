<?php

namespace App\Modules\Checkout\Mail;

use App\Models\User;
use App\Modules\Checkout\Models\Coupon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

/**
 * E-mail do disparo de cupom em lote (pedido 2026-10-10). O botão leva pra
 * loja com ?cupom=CODIGO (o desconto já entra no checkout) e o rodapé tem o
 * link para não receber mais promoções.
 */
class CupomPromocional extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly Coupon $coupon,
        public readonly string $assunto,
        public readonly string $mensagem,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject($this->assunto)
            ->view('emails.cupom-promocional', [
                'linkLoja' => route('catalogo.inicio', ['cupom' => $this->coupon->code]),
                'linkSair' => URL::signedRoute('promocoes.sair', ['user' => $this->user->id]),
            ]);
    }
}

<?php

namespace App\Modules\Newsletter\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\LeadsDigitados;
use App\Modules\Newsletter\Models\NewsletterInscrito;

/**
 * Cliente que compra na KazaKora entra na newsletter (Caderno KazaKora: matérias do blog e
 * ofertas), pedido do Lira em 25/11/2026. Base legal: legítimo interesse do lojista com o
 * próprio cliente (LGPD, art. 7º, IX), informado nos Termos e na Política de Privacidade; todo
 * e-mail tem o descadastro de 1 clique. Quem já saiu da newsletter NUNCA volta por aqui.
 * Só pedido do site KazaKora e pago (FerraKora e outros sites têm marca própria).
 */
class InscreverCliente
{
    public const VERSAO = 'compra-2026-11-25';

    public const TEXTO = 'Ao concluir a compra na KazaKora, o cliente passa a receber o Caderno KazaKora (matérias do blog e ofertas) por e-mail, por legítimo interesse (LGPD, art. 7º, IX), e pode sair a qualquer momento pelo link de descadastro de cada e-mail.';

    /** @return string o que aconteceu: inscrito, ja_ativo, confirmado, descadastrado, ignorado */
    public function doPedido(Order $order): string
    {
        if ($order->origin !== Order::ORIGIN_STORE || ($order->site_origem && $order->site_origem !== 'kazakora') || ! LeadsDigitados::pago($order)) {
            return 'ignorado';
        }

        $email = $order->user?->email ?: $order->shipping_email;
        if (! is_string($email) || ! filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            return 'ignorado';
        }
        $email = NewsletterInscrito::normalizarEmail($email);

        $inscrito = NewsletterInscrito::query()->where('email', $email)->first();
        if ($inscrito?->status === NewsletterInscrito::DESCADASTRADO) {
            return 'descadastrado';
        }
        if ($inscrito?->ativo()) {
            return 'ja_ativo';
        }
        if ($inscrito) {
            $inscrito->confirmar();

            return 'confirmado';
        }

        NewsletterInscrito::query()->create([
            'email' => $email,
            'nome' => $order->user?->name ?: $order->shipping_name,
            'status' => NewsletterInscrito::ATIVO,
            'origem' => 'compra-pedido-'.$order->id,
            'consentimento_versao' => self::VERSAO,
            'consentimento_texto' => self::TEXTO,
            'consentimento_em' => $order->updated_at ?? now(),
            'confirmado_em' => now(),
        ]);

        return 'inscrito';
    }
}

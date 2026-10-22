<?php

namespace App\Modules\WhatsApp\Support;

use App\Modules\Checkout\Models\Order;

/**
 * Números oficiais do WhatsApp por loja (config services.whatsapp.numeros).
 * KazaKora usa as chaves antigas (WHATSAPP_*); FerraKora tem as dela e
 * fica desligada enquanto não tiver phone_number_id.
 */
class WhatsAppNumeros
{
    public const KAZAKORA = 'kazakora';

    public const FERRAKORA = 'ferrakora';

    /** @return list<string> */
    public static function lojas(): array
    {
        return array_keys((array) config('services.whatsapp.numeros', [])) ?: [self::KAZAKORA];
    }

    public static function normalizar(?string $loja): string
    {
        return in_array($loja, self::lojas(), true) ? $loja : self::KAZAKORA;
    }

    public static function phoneNumberId(?string $loja): ?string
    {
        $loja = self::normalizar($loja);
        $id = config("services.whatsapp.numeros.{$loja}.phone_number_id");

        if ($loja === self::KAZAKORA && ! filled($id)) {
            $id = config('services.whatsapp.phone_number_id');
        }

        return filled($id) ? (string) $id : null;
    }

    public static function accessToken(?string $loja): ?string
    {
        $loja = self::normalizar($loja);
        $token = config("services.whatsapp.numeros.{$loja}.access_token") ?: config('services.whatsapp.access_token');

        return filled($token) ? (string) $token : null;
    }

    public static function businessAccountId(?string $loja): ?string
    {
        $loja = self::normalizar($loja);
        $id = config("services.whatsapp.numeros.{$loja}.business_account_id") ?: ($loja === self::KAZAKORA ? config('services.whatsapp.business_account_id') : null);

        return filled($id) ? (string) $id : null;
    }

    /** Número com token e phone_number_id: dá pra mandar mensagem. */
    public static function ativo(?string $loja): bool
    {
        return self::phoneNumberId($loja) !== null && self::accessToken($loja) !== null;
    }

    public static function nome(?string $loja): string
    {
        $loja = self::normalizar($loja);

        return (string) (config("services.whatsapp.numeros.{$loja}.nome") ?: ucfirst($loja));
    }

    public static function site(?string $loja): string
    {
        $loja = self::normalizar($loja);

        return rtrim((string) config("services.whatsapp.numeros.{$loja}.site"), '/');
    }

    /**
     * Loja dona do número que recebeu o webhook (value.metadata.phone_number_id).
     * Sem metadata (payload antigo/teste) = KazaKora. Id que não bate com
     * nenhum número configurado = null (o webhook registra e ignora), exceto
     * quando nem a KazaKora tem id configurado (ambiente sem número fixo: segue
     * como antes, tudo na KazaKora).
     */
    public static function lojaDoPhoneNumberId(?string $phoneNumberId): ?string
    {
        if (! filled($phoneNumberId)) {
            return self::KAZAKORA;
        }

        foreach (self::lojas() as $loja) {
            if (self::phoneNumberId($loja) === (string) $phoneNumberId) {
                return $loja;
            }
        }

        return self::phoneNumberId(self::KAZAKORA) === null ? self::KAZAKORA : null;
    }

    /**
     * Número que manda o aviso do pedido: FerraKora só quando o pedido saiu do
     * site da FerraKora E o número dela está ligado; senão, KazaKora (como
     * sempre foi).
     */
    public static function lojaDoPedido(Order $order): string
    {
        return $order->site_origem === Order::SITE_FERRAKORA && self::ativo(self::FERRAKORA)
            ? self::FERRAKORA
            : self::KAZAKORA;
    }

    /** Template de aviso da loja (chave de services.whatsapp.templates, ex. 'pedido_enviado'). */
    public static function template(string $loja, string $chave, ?string $padrao = null): ?string
    {
        $valor = self::normalizar($loja) === self::KAZAKORA
            ? config("services.whatsapp.templates.{$chave}")
            : config("services.whatsapp.numeros.{$loja}.templates.{$chave}");

        return filled($valor) ? (string) $valor : $padrao;
    }
}

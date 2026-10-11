<?php

namespace App\Modules\WhatsApp\Support;

/**
 * Telefone brasileiro no formato do WhatsApp (55 + DDD + número). Mesma regra
 * que a lista de destinatários das campanhas usava.
 */
class WhatsAppPhone
{
    public static function normalize(?string $phone): ?string
    {
        $phone = ltrim(preg_replace('/\D+/', '', (string) $phone), '0');

        if (! str_starts_with($phone, '55') && strlen($phone) >= 10) {
            $phone = '55'.$phone;
        }

        return preg_match('/^55\d{10,11}$/', $phone) ? $phone : null;
    }
}

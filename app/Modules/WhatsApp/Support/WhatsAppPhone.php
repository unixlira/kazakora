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

    /**
     * O número de quem respondeu é um dos telefones do pedido? O webhook da
     * Meta manda celular de várias regiões SEM o nono dígito (558592466924)
     * enquanto o cadastro tem com o 9 (5585992466924). Achado real
     * 19/10, #3274: "Tudo Certo" ignorado como "outro número" e a NF-e, o
     * e-mail e a etiqueta esperaram os 30 min.
     *
     * @param  array<int, ?string>  $telefones
     */
    public static function mesmoNumero(?string $waId, array $telefones): bool
    {
        $alvo = self::semNonoDigito(self::normalize($waId));
        if ($alvo === null) {
            return false;
        }

        foreach ($telefones as $telefone) {
            if (self::semNonoDigito(self::normalize($telefone)) === $alvo) {
                return true;
            }
        }

        return false;
    }

    /** 55 + DDD + 9 + 8 dígitos (celular) vira 55 + DDD + 8 dígitos, só para comparar. */
    private static function semNonoDigito(?string $phone): ?string
    {
        if ($phone !== null && strlen($phone) === 13 && $phone[4] === '9') {
            return substr($phone, 0, 4).substr($phone, 5);
        }

        return $phone;
    }
}

<?php

namespace App\Modules\WhatsApp\Support;

use App\Modules\Materiais\Support\TelefoneBrasil;

/**
 * Telefone brasileiro no formato do WhatsApp (55 + DDD + número).
 *
 * 10 ou 11 dígitos = DDD + número (inclusive DDD 55, do RS); 12 ou 13
 * começando em 55 = país + DDD + número. Desde 26/11/2026 (pedido 4562: o
 * "55" do país virou DDD e o número foi cortado) o DDD tem que existir e o
 * número de 9 dígitos tem que começar com 9; número cortado ou inventado
 * volta null em vez de virar um WhatsApp de outra pessoa.
 */
class WhatsAppPhone
{
    public static function normalize(?string $phone): ?string
    {
        $phone = ltrim(preg_replace('/\D+/', '', (string) $phone), '0');
        $tamanho = strlen($phone);

        if ($tamanho === 10 || $tamanho === 11) {
            $phone = '55'.$phone;
        } elseif (! (($tamanho === 12 || $tamanho === 13) && str_starts_with($phone, '55'))) {
            return null;
        }

        $nacional = substr($phone, 2);
        if (! TelefoneBrasil::dddValido(substr($nacional, 0, 2))) {
            return null;
        }
        if (strlen($nacional) === 11 && $nacional[2] !== '9') {
            return null;
        }

        return $phone;
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

    /**
     * Identidade do número numa conversa: só dígitos e, no Brasil, sem o nono
     * dígito. Com o 9 (cadastro do pedido) ou sem (webhook da Meta), a chave é
     * a mesma. Achado real 30/10, #3662: disparo e resposta abriam duas conversas.
     */
    public static function chave(?string $waId): ?string
    {
        if (blank($waId)) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $waId);
        if ($digitos === '') {
            return $waId;
        }

        return str_starts_with($digitos, '55') ? self::semNonoDigito($digitos) : $digitos;
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

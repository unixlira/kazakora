<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Materiais\Support\TelefoneBrasil;

/**
 * Celular do checkout e da recuperação do carrinho (pedido do Lira
 * 26/11/2026, pedido 4562): o preenchimento automático do navegador manda
 * "+55 11 99123-4567" e a máscara cortava em 11 dígitos, gravando
 * "(55) 11991-2345" (DDD 55 e número cortado) e o WhatsApp do pedido não
 * saía. Regra, a mesma de maskPhone (resources/js/Shared/useMasks.js):
 * 12 ou 13 dígitos começando em 55 = país + DDD + número, o 55 sai; zero de
 * operadora na frente sai; nunca corta; o DDD tem que existir.
 */
final class CelularDoCheckout
{
    /** DDD + número só com dígitos (sem cortar nada). */
    public static function digitos(?string $valor): string
    {
        return TelefoneBrasil::nacional($valor);
    }

    /** Erro para mostrar no campo, ou null se o número serve. */
    public static function erro(?string $valor): ?string
    {
        $n = self::digitos($valor);

        return match (true) {
            $n === '' => null,
            strlen($n) < 10 => 'Faltam números no celular: digite o DDD e o número.',
            strlen($n) > 11 => 'Celular com números a mais: digite só o DDD e o número.',
            ! TelefoneBrasil::dddValido(substr($n, 0, 2)) => 'DDD '.substr($n, 0, 2).' não existe. Confira o celular.',
            strlen($n) === 11 && $n[2] !== '9' => 'Confira o celular: depois do DDD, o número de 9 dígitos começa com 9.',
            default => null,
        };
    }

    /** "(11) 99123-4567" quando o número serve; senão, o valor como veio. */
    public static function formatar(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '' || self::erro($valor) !== null) {
            return $valor;
        }

        $n = self::digitos($valor);

        return strlen($n) === 11
            ? sprintf('(%s) %s-%s', substr($n, 0, 2), substr($n, 2, 5), substr($n, 7))
            : sprintf('(%s) %s-%s', substr($n, 0, 2), substr($n, 2, 4), substr($n, 6));
    }
}

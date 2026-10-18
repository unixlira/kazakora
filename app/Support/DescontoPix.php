<?php

namespace App\Support;

/**
 * Desconto no Pix da loja (pedido 2026-10-09) — percentual em
 * config/loja.php. Vale só no checkout; o preço da loja é o valor do
 * cadastro, sem acréscimo (pedido 2026-10-18).
 */
class DescontoPix
{
    public static function percentual(): float
    {
        return max(0.0, (float) config('loja.desconto_pix', 5));
    }

    public static function fator(): float
    {
        return 1 + self::percentual() / 100;
    }

    /** Quanto sai de desconto pagando no Pix sobre um valor de produtos. */
    public static function desconto(float $valor): float
    {
        return round($valor * self::percentual() / 100, 2);
    }
}

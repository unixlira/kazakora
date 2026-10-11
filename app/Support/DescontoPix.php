<?php

namespace App\Support;

/**
 * Desconto no Pix da loja (pedido 2026-10-09) — percentual em
 * config/loja.php. O preço da loja guarda o acréscimo: cadastra-se o valor
 * do Pix e grava-se +5%; os marketplaces usam o preço sem o acréscimo.
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

    /** Valor digitado no cadastro → preço gravado na loja (+5%). */
    public static function comAcrescimo(?float $valor): ?float
    {
        return $valor === null ? null : round($valor * self::fator(), 2);
    }

    /** Preço da loja → valor sem o acréscimo (o que foi digitado / vai pros marketplaces). */
    public static function semAcrescimo(?float $valor): ?float
    {
        return $valor === null ? null : round($valor / self::fator(), 2);
    }

    /** Quanto sai de desconto pagando no Pix sobre um valor de produtos. */
    public static function desconto(float $valor): float
    {
        return round($valor * self::percentual() / 100, 2);
    }
}

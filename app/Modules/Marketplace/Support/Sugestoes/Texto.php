<?php

namespace App\Modules\Marketplace\Support\Sugestoes;

/** Números no formato brasileiro para as frases das sugestões. */
final class Texto
{
    public static function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    public static function pct(float $valor): string
    {
        return number_format($valor, 1, ',', '.').'%';
    }

    public static function num(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }

    public static function inteiro(int $valor): string
    {
        return number_format($valor, 0, ',', '.');
    }
}

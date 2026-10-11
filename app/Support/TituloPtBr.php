<?php

namespace App\Support;

/**
 * Nome no formato de título em português (pedido 2026-10-10): primeira letra
 * maiúscula em cada palavra e conectivos em minúsculas — "ORGANIZAÇÃO DE
 * COZINHA" vira "Organização de Cozinha". Siglas conhecidas ficam em caixa alta.
 */
class TituloPtBr
{
    private const MINUSCULAS = ['e', 'de', 'da', 'do', 'das', 'dos', 'para', 'com', 'em', 'na', 'no', 'nas', 'nos', 'a', 'o', 'as', 'os', 'ou', 'por'];

    private const SIGLAS = ['USB', 'LED', 'TV', 'PC', 'PET', 'DIY', 'HD', 'UV'];

    public static function formatar(?string $texto): string
    {
        $palavras = preg_split('/\s+/u', trim((string) $texto), -1, PREG_SPLIT_NO_EMPTY);

        return collect($palavras)->map(function (string $palavra, int $indice) {
            $maiuscula = mb_strtoupper($palavra);
            if (in_array($maiuscula, self::SIGLAS, true)) {
                return $maiuscula;
            }
            $minuscula = mb_strtolower($palavra);
            if ($indice > 0 && in_array($minuscula, self::MINUSCULAS, true)) {
                return $minuscula;
            }

            return mb_convert_case($minuscula, MB_CASE_TITLE, 'UTF-8');
        })->implode(' ');
    }
}

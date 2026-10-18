<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Nome de pessoa sempre salvo com a primeira letra de cada palavra em
 * maiúscula e o resto em minúscula (pedido 2026-10-17): "JOSÉ roberto LIRA"
 * vira "José Roberto Lira", venha de onde vier (cadastro, checkout, perfil).
 *
 * Fica no mutator do model para cobrir qualquer caminho de escrita. Use no
 * model que tem a coluna `name`, ou passe outra coluna em $nomesCapitalizados.
 */
trait NomeCapitalizado
{
    public static function capitalizarNome(?string $nome): ?string
    {
        if ($nome === null) {
            return null;
        }

        $nome = trim(preg_replace('/\s+/u', ' ', $nome));

        return mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8');
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $valor) => static::capitalizarNome($valor));
    }
}

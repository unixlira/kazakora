<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * Ordenação de listagem paginada no servidor (DataTable em modo servidor,
 * ver resources/js/Shared/useServerTable.js). A coluna vem da URL
 * (?sort=...&direction=...), então SEMPRE passa por uma lista fechada de
 * colunas permitidas antes de chegar no orderBy — nunca concatena o que
 * veio do navegador direto no SQL.
 */
final class TableSort
{
    /**
     * @param  array<string, string>  $allowed  id da coluna no front => expressão/coluna SQL
     * @return array{key: string|null, column: string, direction: string} key = coluna escolhida pelo usuário (null = ordenação padrão da tela)
     */
    public static function resolve(Request $request, array $allowed, string $defaultColumn, string $defaultDirection = 'desc', string $prefix = ''): array
    {
        $key = $request->string($prefix.'sort')->toString();
        $direction = strtolower($request->string($prefix.'direction')->toString()) === 'asc' ? 'asc' : 'desc';

        if ($key === '' || ! array_key_exists($key, $allowed)) {
            return ['key' => null, 'column' => $defaultColumn, 'direction' => $defaultDirection];
        }

        return ['key' => $key, 'column' => $allowed[$key], 'direction' => $direction];
    }

    /**
     * Busca livre da tela pronta pro LIKE (null = sem busca). Curinga
     * digitado pelo usuário (% _) não é escapado de propósito: o caractere
     * de escape padrão muda entre MySQL (produção) e SQLite (testes), e
     * "50%" casando um pouco mais do que devia numa busca de tela é
     * inofensivo.
     */
    public static function likeTerm(?string $search): ?string
    {
        $search = trim((string) $search);

        if ($search === '') {
            return null;
        }

        return '%'.$search.'%';
    }
}

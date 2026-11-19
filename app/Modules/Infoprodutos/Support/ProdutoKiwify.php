<?php

namespace App\Modules\Infoprodutos\Support;

use Illuminate\Support\Str;

/**
 * Produto da Kiwify => [produto do site, tipo] (principal, order_bump,
 * upsell, downsell). Primeiro pelo product_id (config
 * infoprodutos.kiwify.produtos), depois pelo nome; sem achar = [null, 'outro'].
 */
class ProdutoKiwify
{
    /** @return array{0: ?string, 1: string} */
    public static function classificar(?string $produtoId, ?string $nome): array
    {
        $mapa = (array) config('infoprodutos.kiwify.produtos');
        if ($produtoId !== null && isset($mapa[$produtoId])) {
            return [(string) $mapa[$produtoId][0], (string) $mapa[$produtoId][1]];
        }

        $nome = Str::of((string) $nome)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
        if ($nome !== '') {
            foreach ((array) config('infoprodutos.kiwify.nomes') as [$trecho, $produto, $tipo]) {
                if (str_contains($nome, $trecho)) {
                    return [$produto, $tipo];
                }
            }
        }

        return [null, 'outro'];
    }
}

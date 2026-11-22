<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Texto de promoção que substitui a "novidade" (Gemini) nos disparos 2 a 6 da
 * sequência de carrinho abandonado enquanto a promoção do produto vale
 * (pedido do Lira em 22/11/2026: Black Friday do Livro Falante com a Lousa de
 * brinde até 30/11 e mais 3% no Pix). Config loja.carrinho_promocoes; cada
 * item casa pelo slug do produto do carrinho ou do produto pai (variação).
 * O 1º disparo (lembrete) continua igual. Vencida a data, volta a novidade.
 */
class PromocaoDoCarrinho
{
    public static function texto(?Product $produto, ?Carbon $agora = null): ?string
    {
        if (! $produto) {
            return null;
        }
        $agora ??= now();
        $slugs = array_filter([(string) $produto->slug, (string) ($produto->parent_product_id ? Product::withTrashed()->whereKey($produto->parent_product_id)->value('slug') : '')]);

        foreach ((array) config('loja.carrinho_promocoes', []) as $promo) {
            $trecho = (string) ($promo['slug_contem'] ?? '');
            $ate = isset($promo['ate']) ? Carbon::parse((string) $promo['ate'], 'America/Sao_Paulo') : null;
            if ($trecho === '' || ! $ate || $agora->greaterThan($ate) || blank($promo['texto'] ?? null)) {
                continue;
            }
            foreach ($slugs as $slug) {
                if (str_contains($slug, $trecho)) {
                    // Variável de template da Meta não aceita quebra de linha.
                    return trim((string) preg_replace('/\s+/u', ' ', (string) $promo['texto']));
                }
            }
        }

        return null;
    }
}

<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Str;

/**
 * Search Console (pedido 2026-10-12): ~156 URLs da Nuvemshop no formato
 * /produtos/<slug>-<id>/ dão 404. Acha o produto atual equivalente para um
 * 301: primeiro o slug exato sem o "-<id>", depois o produto ativo com mais
 * palavras em comum (mínimo 60% das palavras do slug antigo). Sem par
 * confiável devolve null e a página segue 404 (redirecionar tudo para a
 * vitrine vira "soft 404" no Google).
 *
 * Conectivos (de, com, para...) não contam (2026-10-15): "luminaria-de-parede-
 * com-sensor-de-movimento" ia para a mini câmera A9 só por "de", "com",
 * "sensor" e "movimento".
 */
class ProdutoDaLojaAntiga
{
    private const CONECTIVOS = ['de', 'da', 'do', 'das', 'dos', 'com', 'sem', 'para', 'pra', 'em', 'no', 'na', 'nos', 'nas', 'por', 'ate', 'um', 'uma'];

    public static function encontrar(string $slugAntigo): ?Product
    {
        $slug = Str::slug(preg_replace('/-\d+$/', '', trim($slugAntigo, '/')));
        if ($slug === '') {
            return null;
        }

        $ativos = Product::query()
            ->where('is_active', true)
            ->whereNull('parent_product_id')
            ->whereNotNull('slug')
            ->get(['id', 'slug']);

        if ($exato = $ativos->firstWhere('slug', $slug)) {
            return $exato;
        }

        $palavras = self::palavras($slug);
        if (count($palavras) < 2) {
            return null;
        }

        $melhor = null;
        $melhorNota = 0.0;
        foreach ($ativos as $produto) {
            $comuns = count(array_intersect($palavras, self::palavras($produto->slug)));
            $nota = $comuns / count($palavras);
            if ($nota > $melhorNota) {
                [$melhor, $melhorNota] = [$produto, $nota];
            }
        }

        return $melhorNota >= 0.6 ? $melhor : null;
    }

    /** @return list<string> */
    private static function palavras(string $slug): array
    {
        return array_values(array_unique(array_filter(
            explode('-', $slug),
            fn ($p) => mb_strlen($p) > 1 && ! in_array($p, self::CONECTIVOS, true),
        )));
    }
}

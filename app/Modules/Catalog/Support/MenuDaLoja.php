<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\Cache;

/**
 * Dados que o topo da loja usa em toda página (pedido 2026-10-10): os
 * departamentos do mega menu e o maior desconto de verdade da faixa
 * promocional. Os dois ficam 10 min em cache.
 */
class MenuDaLoja
{
    /**
     * Departamentos com produto ativo, do que tem mais produto pro que tem
     * menos. Sem foto cadastrada na categoria, usa a foto de um produto dela.
     *
     * @return list<array{id: int, name: string, slug: string, image_url: ?string, total: int}>
     */
    public static function departamentos(): array
    {
        return Cache::remember('loja:departamentos', now()->addMinutes(10), function () {
            $ativos = fn ($query) => $query->where('is_active', true)->whereNull('parent_product_id');

            return Category::query()
                ->whereHas('products', $ativos)
                ->withCount(['products' => $ativos])
                ->orderByDesc('products_count')
                ->get(['id', 'name', 'slug', 'image_path'])
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'image_url' => $category->image_url ?? ProductImage::query()
                        ->whereHas('product', fn ($query) => $ativos($query)->where('category_id', $category->id))
                        ->orderByDesc('is_primary')->orderBy('position')
                        ->first()?->thumb_url,
                    'total' => (int) $category->products_count,
                ])
                ->all();
        });
    }

    /**
     * Maior desconto real entre os produtos ativos (desconto cadastrado ou
     * oferta do dia), em % inteiro. A faixa do topo só mostra o que existe.
     */
    public static function maiorDesconto(): int
    {
        return Cache::remember('loja:maior-desconto', now()->addMinutes(10), function () {
            $ativos = Product::query()->where('is_active', true)->whereNull('parent_product_id')->where('price', '>', 0);

            $maior = (float) (clone $ativos)->max('discount_percentage');

            (clone $ativos)->where('discount_amount', '>', 0)->get(['id', 'price', 'discount_amount'])
                ->each(function (Product $produto) use (&$maior) {
                    $maior = max($maior, (float) $produto->discount_amount * 100 / (float) $produto->price);
                });

            $ofertas = OfertaDoDia::precosDeHoje();
            if ($ofertas) {
                (clone $ativos)->whereIn('id', array_keys($ofertas))->get()
                    ->each(function (Product $produto) use (&$maior, $ofertas) {
                        $de = $produto->precoSemOferta();
                        if ($de > 0) {
                            $maior = max($maior, (1 - $ofertas[$produto->id] / $de) * 100);
                        }
                    });
            }

            return (int) floor(min($maior, 99));
        });
    }
}

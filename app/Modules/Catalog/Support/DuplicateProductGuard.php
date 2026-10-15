<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

/**
 * Trava de produto duplicado no cadastro (pedido do Lira em 2026-10-15):
 * mesmo nome, ou foto idêntica a de outro produto, não entra. Produtos da
 * mesma família (pai e variações) podem repetir nome e foto: é o caso normal
 * de cor/tamanho diferente.
 */
class DuplicateProductGuard
{
    /** Produto não apagado com o mesmo nome (ignora caixa e espaços nas pontas). */
    public function productWithSameName(string $name, ?int $parentId = null, ?int $ignoreId = null): ?Product
    {
        $normalized = mb_strtolower(trim($name));

        if ($normalized === '') {
            return null;
        }

        return Product::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($parentId, fn ($query) => $query->whereKeyNot($parentId)->where(
                fn ($family) => $family->whereNull('parent_product_id')->orWhere('parent_product_id', '!=', $parentId)
            ))
            ->first();
    }

    /** Outro produto, fora da família, que já tem uma foto com o mesmo conteúdo. */
    public function productWithIdenticalImage(Product $product, string $path, string $disk = 'public'): ?Product
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return null;
        }

        $hash = md5($storage->get($path));
        $family = collect([$product->id, $product->parent_product_id])->filter();
        $family = $family->merge(Product::query()->where('parent_product_id', $product->parent_product_id ?? $product->id)->pluck('id'));

        $candidates = ProductImage::query()
            ->whereNotIn('product_id', $family->unique()->all())
            ->whereHas('product')
            ->get(['id', 'product_id', 'path']);

        foreach ($candidates as $candidate) {
            if ($candidate->path !== $path && $storage->exists($candidate->path) && md5($storage->get($candidate->path)) === $hash) {
                return $candidate->product;
            }
        }

        return null;
    }
}

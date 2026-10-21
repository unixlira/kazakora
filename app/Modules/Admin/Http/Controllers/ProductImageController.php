<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\DuplicateProductGuard;
use App\Modules\Catalog\Support\ProductImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    public function store(Request $request, Product $product): RedirectResponse
    {
        // 4MB used to be the cap here — too tight for an unedited phone
        // photo (routinely 5-10MB before any compression), so uploads were
        // silently rejected client-side with no visible error. 15MB comfortably
        // covers that; optimize() below still shrinks it down to ~150-250KB
        // on disk regardless of how big the original upload was.
        $request->validate([
            'image' => ['required', 'image', 'max:15360'],
        ]);

        $path = $request->file('image')->store("products/{$product->id}", 'public');
        $path = ProductImageOptimizer::optimize($path) ?? $path;

        // Trava de duplicado (2026-10-15): foto idêntica à de outro produto
        // (fora da família de variações) é recusada.
        $igual = app(DuplicateProductGuard::class)->productWithIdenticalImage($product, $path);
        if ($igual) {
            Storage::disk('public')->delete($path);

            return back()->with('error', "Essa foto já está no produto #{$igual->id} ({$igual->name}). Se for o mesmo item, cadastre como variação dele.");
        }

        $product->images()->create([
            'path' => $path,
            'position' => $product->images()->count(),
            'is_primary' => $product->images()->count() === 0,
        ]);

        return back()->with('success', 'Foto adicionada.');
    }

    /**
     * Ordem da galeria vinda do arrastar e soltar do admin. A primeira foto
     * vira a principal (capa), igual às importações de canal, que já marcam
     * a posição 0 como is_primary.
     */
    public function reorder(Request $request, Product $product): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        $atuais = $product->images()->pluck('id')->all();
        abort_if(count($ids) !== count($atuais) || array_diff($ids, $atuais) !== [], 422, 'A lista de fotos mudou. Recarregue a página.');

        DB::transaction(function () use ($product, $ids) {
            foreach ($ids as $posicao => $id) {
                $product->images()->whereKey($id)->update([
                    'position' => $posicao,
                    'is_primary' => $posicao === 0,
                ]);
            }
        });

        return back()->with('success', 'Ordem das fotos salva.');
    }

    /**
     * Foto da variação (pedido 2026-10-20): o Lira escolhe na aba Variações
     * qual foto do produto aparece quando o cliente escolhe essa variação.
     * Só uma por produto; `image_id` vazio volta para a escolha automática
     * (a principal).
     */
    public function variacao(Request $request, Product $product): RedirectResponse
    {
        $imageId = $request->validate([
            'image_id' => ['nullable', 'integer'],
        ])['image_id'] ?? null;

        abort_if($imageId && ! $product->images()->whereKey($imageId)->exists(), 422, 'Essa foto não é deste produto. Recarregue a página.');

        DB::transaction(function () use ($product, $imageId) {
            $product->images()->where('is_variant', true)->update(['is_variant' => false]);
            if ($imageId) {
                $product->images()->whereKey($imageId)->update(['is_variant' => true]);
            }
        });

        return back()->with('success', $imageId
            ? "Foto da variação de \"{$product->name}\" salva."
            : "\"{$product->name}\" voltou para a foto automática (a principal).");
    }

    public function destroy(Product $product, ProductImage $image): RedirectResponse
    {
        abort_if($image->product_id !== $product->id, 404);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        if (! $product->images()->where('is_primary', true)->exists()) {
            $product->images()->oldest('position')->first()?->update(['is_primary' => true]);
        }

        return back()->with('success', 'Foto removida.');
    }
}

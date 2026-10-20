<?php

namespace App\Modules\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\App\Services\CatalogoApp;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Favoritos da conta no app (2026-10-18), a mesma tabela do coração do site
 * (FavoriteController): o que o cliente marcar no app aparece no site e
 * vice-versa. Só anúncio (pai) ativo.
 */
class FavoritoAppController extends Controller
{
    public function index(Request $request, CatalogoApp $catalogo): JsonResponse
    {
        $ids = Favorite::idsDoUsuario($request->user()->id);
        $produtos = Product::query()->forCard()->whereIn('id', $ids)->where('is_active', true)->whereNull('parent_product_id')->get();

        return response()->json([
            'ids' => $ids,
            'produtos' => $produtos->map(fn (Product $produto) => $catalogo->card($produto))->values(),
        ]);
    }

    public function store(Request $request, int $produto): JsonResponse
    {
        $product = Product::query()->whereKey($produto)->where('is_active', true)->first();
        if (! $product) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        Favorite::query()->firstOrCreate(['user_id' => $request->user()->id, 'product_id' => $product->parent_product_id ?? $product->id]);

        return response()->json(['ids' => Favorite::idsDoUsuario($request->user()->id)]);
    }

    public function destroy(Request $request, int $produto): JsonResponse
    {
        Favorite::query()->where('user_id', $request->user()->id)->where('product_id', $produto)->delete();

        return response()->json(['ids' => Favorite::idsDoUsuario($request->user()->id)]);
    }
}

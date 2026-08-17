<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FavoriteController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::query()
            ->with('category:id,name,slug', 'images')
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->whereHas('favorites', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest()
            ->paginate(12);

        $purchasedProductIds = OrderItem::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', Order::STATUS_COMPLETED))
            ->pluck('product_id')
            ->unique()
            ->values();

        // Pedido explícito 2026-08-17 (variações de produto): comprou a
        // variação "10 Polegadas" tem que poder avaliar a variação "8
        // Polegadas" favoritada — mesmo item físico. Expande cada id
        // comprado pro grupo de variações inteiro (variantGroupIds()
        // devolve só o próprio id quando o produto não tem variação
        // nenhuma, sem regressão pro caso comum).
        $reviewableProductIds = Product::query()
            ->whereIn('id', $purchasedProductIds)
            ->get(['id', 'parent_product_id'])
            ->flatMap(fn (Product $product) => $product->variantGroupIds())
            ->unique()
            ->values();

        return Inertia::render('Catalog/Favoritos', [
            'products' => $products,
            'favoriteIds' => Favorite::query()->where('user_id', $request->user()->id)->pluck('product_id'),
            'reviewableProductIds' => $reviewableProductIds,
            'reviewedProductIds' => Review::query()->where('user_id', $request->user()->id)->pluck('product_id'),
        ]);
    }

    public function toggle(Request $request, Product $product): RedirectResponse
    {
        $favorite = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->first();

        if ($favorite) {
            $favorite->delete();

            return back()->with('success', 'Produto removido dos favoritos.');
        }

        Favorite::create([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);

        return back()->with('success', 'Produto adicionado aos favoritos.');
    }
}

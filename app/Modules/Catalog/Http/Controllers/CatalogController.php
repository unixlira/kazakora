<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesShopeeAuthorizationLanding;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Operacional\Models\ShippingMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    use HandlesShopeeAuthorizationLanding;

    /**
     * Um dos 3 destinos plausíveis do redirect de autorização "Seller In
     * House" da Shopee — ver HandlesShopeeAuthorizationLanding.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->shopeeAuthorizationLandingRedirect($request)) {
            return $redirect;
        }

        $search = $request->string('search')->trim();
        $tipo = $request->query('tipo');

        $baseQuery = Product::query()
            ->with('category:id,name,slug', 'images')
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('is_active', true)
            ->when($search->isNotEmpty(), fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($tipo === 'destaque', fn ($query) => $query->where('is_featured', true))
            ->when($tipo === 'lancamento', fn ($query) => $query->where('is_new_release', true));

        $products = (clone $baseQuery)
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Catalog/Home', [
            'banners' => Banner::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'title', 'image_path', 'image_path_mobile', 'link_url']),
            'featuredProducts' => Product::query()
                ->with('category:id,name,slug', 'images')
                ->withAvg('reviews', 'rating')
                ->withCount('reviews')
                ->where('is_active', true)
                ->where('is_featured', true)
                ->latest()
                ->take(5)
                ->get(),
            'products' => $products,
            'categories' => Category::query()
                ->whereHas('products', fn ($query) => $query->where('is_active', true))
                ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
                ->orderByDesc('products_count')
                ->get(['id', 'name', 'slug', 'image_path']),
            'favoriteIds' => $request->user()
                ? Favorite::query()->where('user_id', $request->user()->id)->pluck('product_id')
                : [],
            'reviewableProductIds' => $this->reviewableProductIds($request),
            'reviewedProductIds' => $request->user()
                ? Review::query()->where('user_id', $request->user()->id)->pluck('product_id')
                : [],
            'filters' => $request->only('search', 'tipo'),
        ]);
    }

    public function show(Request $request, Product $product): Response
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'category:id,name,slug',
            'images' => fn ($query) => $query->orderBy('position'),
            'quantityDiscounts',
        ]);
        $product->loadCount('reviews');
        $product->loadAvg('reviews', 'rating');

        $salesCount = OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($query) => $query->where('status', Order::STATUS_COMPLETED))
            ->sum('quantity');

        $reviews = Review::query()
            ->where('product_id', $product->id)
            ->with(['user:id,name', 'images'])
            ->latest()
            ->get();

        $user = $request->user();

        $relatedProducts = Product::query()
            ->with('category:id,name,slug', 'images')
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('is_active', true)
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($query) => $query->where('category_id', $product->category_id))
            ->inRandomOrder()
            ->take(5)
            ->get();

        if ($relatedProducts->count() < 5) {
            $excludeIds = $relatedProducts->pluck('id')->push($product->id);

            $relatedProducts = $relatedProducts->concat(
                Product::query()
                    ->with('category:id,name,slug', 'images')
                    ->withAvg('reviews', 'rating')
                    ->withCount('reviews')
                    ->where('is_active', true)
                    ->whereNotIn('id', $excludeIds)
                    ->latest()
                    ->take(5 - $relatedProducts->count())
                    ->get()
            );
        }

        $relatedIds = $relatedProducts->pluck('id');

        // Pedido explícito 2026-08-17 (variações de produto, estilo
        // Shopee/Mercado Livre): outras variações do mesmo grupo, só as
        // ATIVAS (uma variação despublicada/desativada não pode aparecer
        // como opção clicável pro cliente) — inclui foto principal e
        // preço pra já dar pra montar o seletor sem outra viagem ao
        // banco. Vazio quando o produto não tem variação nenhuma
        // (variantGroupIds() sempre inclui o próprio id, então filtra
        // fora com o where('id','!=')).
        $variations = Product::query()
            ->whereIn('id', $product->variantGroupIds())
            ->where('id', '!=', $product->id)
            ->where('is_active', true)
            ->with(['images' => fn ($query) => $query->where('is_primary', true)->limit(1)])
            ->get(['id', 'name', 'slug', 'variation', 'price', 'stock']);

        return Inertia::render('Catalog/ProductDetail', [
            'product' => $product,
            'variations' => $variations,
            'reviews' => $reviews,
            'shippingMethods' => ShippingMethod::query()
                ->where('is_active', true)
                ->orderBy('price')
                ->get(['id', 'name', 'estimated_days', 'price']),
            'isFavorite' => $user
                ? Favorite::query()->where('user_id', $user->id)->where('product_id', $product->id)->exists()
                : false,
            // Pedido explícito 2026-08-17 (variações de produto): comprou
            // a variação "10 Polegadas" tem que poder avaliar a variação
            // "8 Polegadas" da mesma página de produto — mesmo item
            // físico, só variação diferente. variantGroupIds() inclui o
            // próprio id quando o produto não tem variação nenhuma
            // (comportamento de sempre, sem regressão).
            'canReview' => $user ? (bool) array_intersect($product->variantGroupIds(), $this->reviewableProductIds($request)) : false,
            'hasReviewed' => $user
                ? Review::query()->where('user_id', $user->id)->where('product_id', $product->id)->exists()
                : false,
            'relatedProducts' => $relatedProducts->values(),
            'relatedFavoriteIds' => $user
                ? Favorite::query()->where('user_id', $user->id)->whereIn('product_id', $relatedIds)->pluck('product_id')
                : [],
            // Mesmo raciocínio do canReview acima: um "relacionado" pode
            // ser justamente uma variação irmã do produto atual (mesma
            // categoria) — comprou uma variação, pode avaliar a outra.
            'relatedReviewableIds' => $user
                ? $relatedProducts
                    ->filter(fn (Product $related) => array_intersect($related->variantGroupIds(), $this->reviewableProductIds($request)))
                    ->pluck('id')
                    ->values()
                    ->all()
                : [],
            'relatedReviewedIds' => $user
                ? Review::query()->where('user_id', $user->id)->whereIn('product_id', $relatedIds)->pluck('product_id')
                : [],
            'salesCount' => (int) $salesCount,
        ]);
    }

    public function shipping(Product $product): Response
    {
        abort_unless($product->is_active, 404);

        return Inertia::render('Catalog/ProductShipping', [
            'product' => $product->only('id', 'name', 'slug'),
        ]);
    }

    private function reviewableProductIds(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        return OrderItem::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id)->where('status', Order::STATUS_COMPLETED))
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();
    }
}

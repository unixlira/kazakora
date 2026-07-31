<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Operacional\Models\ShippingMethod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
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
            ->with('user:id,name')
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

        return Inertia::render('Catalog/ProductDetail', [
            'product' => $product,
            'reviews' => $reviews,
            'shippingMethods' => ShippingMethod::query()
                ->where('is_active', true)
                ->orderBy('price')
                ->get(['id', 'name', 'estimated_days', 'price']),
            'isFavorite' => $user
                ? Favorite::query()->where('user_id', $user->id)->where('product_id', $product->id)->exists()
                : false,
            'canReview' => $user ? in_array($product->id, $this->reviewableProductIds($request), true) : false,
            'hasReviewed' => $user
                ? Review::query()->where('user_id', $user->id)->where('product_id', $product->id)->exists()
                : false,
            'relatedProducts' => $relatedProducts->values(),
            'relatedFavoriteIds' => $user
                ? Favorite::query()->where('user_id', $user->id)->whereIn('product_id', $relatedIds)->pluck('product_id')
                : [],
            'relatedReviewableIds' => $user
                ? array_values(array_intersect($this->reviewableProductIds($request), $relatedIds->all()))
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

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
                ->take(6)
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

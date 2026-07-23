<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim();

        $baseQuery = Product::query()
            ->with('category:id,name,slug', 'images')
            ->where('is_active', true)
            ->when($search->isNotEmpty(), fn ($query) => $query->where('name', 'like', '%'.$search.'%'));

        $featured = $search->isEmpty()
            ? (clone $baseQuery)->latest()->first()
            : null;

        $products = (clone $baseQuery)
            ->when($featured, fn ($query) => $query->whereKeyNot($featured->id))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Catalog/Home', [
            'featured' => $featured,
            'products' => $products,
            'categories' => Category::query()
                ->whereHas('products', fn ($query) => $query->where('is_active', true))
                ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
                ->orderByDesc('products_count')
                ->get(['id', 'name', 'slug']),
            'favoriteIds' => $request->user()
                ? Favorite::query()->where('user_id', $request->user()->id)->pluck('product_id')
                : [],
            'filters' => $request->only('search'),
        ]);
    }
}

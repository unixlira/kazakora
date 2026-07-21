<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::query()
            ->with('category:id,name,slug')
            ->where('is_active', true)
            ->when($request->string('search')->trim()->isNotEmpty(), fn ($query) => $query->where(
                'name', 'like', '%'.$request->string('search')->trim().'%'
            ))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Catalog/Home', [
            'products' => $products,
            'filters' => $request->only('search'),
        ]);
    }
}

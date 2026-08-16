<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\ProductImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $product->images()->create([
            'path' => $path,
            'position' => $product->images()->count(),
            'is_primary' => $product->images()->count() === 0,
        ]);

        return back()->with('success', 'Foto adicionada.');
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

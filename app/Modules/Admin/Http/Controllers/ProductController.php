<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly StockManager $stock,
        private readonly MarketplaceDriverManager $drivers,
    ) {
    }

    public function index(Request $request): Response
    {
        $products = Product::query()
            ->with('category:id,name')
            ->when($request->string('search')->trim()->isNotEmpty(), fn ($query) => $query->where(
                fn ($query) => $query
                    ->where('name', 'like', '%'.$request->string('search').'%')
                    ->orWhere('sku', 'like', '%'.$request->string('search').'%')
            ))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Products/Index', [
            'products' => $products,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Products/Create', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $validated['slug'] = $this->uniqueSlug($validated['name']);

        $product = Product::create($validated);

        return redirect()
            ->route('admin.products.edit', $product)
            ->with('success', 'Produto criado com sucesso. Agora complete os dados fiscais, fotos, vídeo e canais de venda.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('Admin/Products/Edit', [
            'product' => $product,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'fiscalData' => $product->fiscalData,
            'images' => $product->images,
            'channels' => $this->drivers->channels(),
            'channelListings' => $product->channelListings,
            'stockMovements' => $product->stockMovements()->latest()->limit(10)->get(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validated($request, $product->id);
        $newStock = $validated['stock'];
        unset($validated['stock']);

        if ($validated['name'] !== $product->name) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $product->id);
        }

        $product->update($validated);

        $delta = $newStock - $product->stock;

        if ($delta !== 0) {
            $this->stock->adjust($product, $delta, StockMovement::TYPE_ADJUSTMENT, reason: 'Ajuste manual no cadastro do produto');
        }

        return redirect()->route('admin.products.index')->with('success', 'Produto atualizado com sucesso.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return back()->with('success', 'Produto removido com sucesso.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'sku' => [
                'required', 'string', 'max:64',
                Rule::unique('products', 'sku')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (
            Product::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-".++$suffix;
        }

        return $slug;
    }
}

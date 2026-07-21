<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Services\SkuGeneratorService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    private const MAX_SKU_ATTEMPTS = 5;

    public function __construct(
        private readonly StockManager $stock,
        private readonly MarketplaceDriverManager $drivers,
        private readonly SkuGeneratorService $skuGenerator,
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

        $category = $validated['category_id']
            ? Category::query()->find($validated['category_id'])
            : null;

        $product = $this->createWithGeneratedSku($validated, $category?->name);

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

        // The SKU is generated once at creation and never touched again by a
        // regular update — only backfilled here if a legacy row somehow has
        // none, per the "never recreate an existing SKU" rule.
        if (blank($product->sku)) {
            $category = $validated['category_id']
                ? Category::query()->find($validated['category_id'])
                : null;

            $validated['sku'] = $this->skuGenerator->generate($this->skuPayload($validated, $category?->name));
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

    /**
     * Creates the product with a freshly generated SKU, retrying with a new
     * SKU if a race with another request causes a unique constraint hit.
     */
    private function createWithGeneratedSku(array $validated, ?string $categoryName): Product
    {
        $attempts = 0;

        do {
            $attempts++;
            $validated['sku'] = $this->skuGenerator->generate($this->skuPayload($validated, $categoryName));

            try {
                return Product::create($validated);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempts >= self::MAX_SKU_ATTEMPTS) {
                    throw $exception;
                }
            }
        } while (true);
    }

    private function skuPayload(array $validated, ?string $categoryName): array
    {
        return [
            'categoria' => $categoryName,
            'produto' => $validated['name'] ?? null,
            'marca' => $validated['brand'] ?? null,
            'modelo' => $validated['model'] ?? null,
            'cor' => $validated['color'] ?? null,
            'variacao' => $validated['variation'] ?? null,
        ];
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:100'],
            'variation' => ['nullable', 'string', 'max:100'],
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

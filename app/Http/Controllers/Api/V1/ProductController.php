<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Services\SkuGeneratorService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * API pública, pedido explícito 2026-08-21 — mesmas regras de validação e
 * mesma geração de SKU do painel admin (ver Admin\ProductController), só
 * respondendo JSON em vez de Inertia/redirect. Autorização por
 * ability do token (`abilities:cadastros.*`, ver routes/api_v1.php),
 * reaproveitando o MESMO vocabulário de permissão do RBAC interno — ver
 * App\Models\ApiPartner.
 */
class ProductController extends Controller
{
    private const MAX_SKU_ATTEMPTS = 5;

    public function __construct(
        private readonly StockManager $stock,
        private readonly SkuGeneratorService $skuGenerator,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->with(['category', 'images'])
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->when($validated['category_id'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', $validated['is_active']))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return ProductResource::collection($products);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(['category', 'images']));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, includeStock: true);

        $validated['slug'] = $this->uniqueSlug($validated['name']);

        $category = ($validated['category_id'] ?? null)
            ? Category::query()->find($validated['category_id'])
            : null;

        $product = $this->createWithGeneratedSku($validated, $category?->name);

        // BUG REAL 2026-08-21 (achado testando de verdade): 'stock' não
        // era enviado no create() (não fazia parte de $validated) — o
        // valor DEFAULT 0 da coluna só existe no banco, o objeto Eloquent
        // em memória logo após create() nunca foi recarregado, então
        // saía com stock=null na resposta (só reaparecia certo numa
        // consulta SEPARADA depois). load() só recarrega RELAÇÕES, não os
        // atributos do próprio model — fresh() força um select() real.
        return (new ProductResource($product->fresh(['category', 'images'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Product $product): ProductResource
    {
        $validated = $this->validated($request, $product->id, includeStock: false);

        if ($validated['name'] !== $product->name) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $product->id);
        }

        $product->update($validated);

        return new ProductResource($product->fresh(['category', 'images']));
    }

    /**
     * Endpoint SEPARADO do update() geral de propósito, com ability
     * própria (`estoque.adjust`, ver routes/api_v1.php) — um parceiro só
     * autorizado a mexer em estoque (não em preço/descrição/etc.) não
     * deveria conseguir nada disso via um único endpoint genérico. Mesmo
     * modelo de "ajuste, não valor absoluto" que o painel admin usa (ver
     * bug real documentado em Admin\ProductController::update()).
     */
    public function adjustStock(Request $request, Product $product): ProductResource
    {
        $validated = $request->validate([
            'adjustment' => ['required', 'integer', 'not_in:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->stock->adjust(
            $product,
            $validated['adjustment'],
            StockMovement::TYPE_ADJUSTMENT,
            reason: $validated['reason'] ?? 'Ajuste via API pública',
        );

        return new ProductResource($product->fresh(['category', 'images']));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * BUG REAL 2026-08-21 (achado testando de verdade contra a API, não só
     * lendo o código): campo opcional ausente do payload JSON some do
     * array de `$request->validate()` (sem 'sometimes'/valor default, um
     * campo NUNCA enviado simplesmente não aparece na saída) — acesso
     * direto tipo `$validated['category_id']` explodia com "Undefined
     * array key". O formulário do admin nunca pegava isso porque o objeto
     * reativo do Vue sempre manda TODO campo (mesmo vazio/null); um
     * parceiro de API de verdade, batendo com um JSON minimalista, expõe
     * isso na hora. `array_merge` com defaults explícitos garante que todo
     * campo opcional sempre existe na saída, nunca precisa de `?? null`
     * espalhado pelo controller inteiro.
     *
     * $includeStock=false no update() de propósito — mesmíssimo motivo do
     * painel admin (ver bug real documentado lá): se 'stock' entrasse aqui
     * com um default de 0, TODO update() que não mandasse 'stock'
     * explicitamente zeraria o estoque de verdade do produto. Ajuste de
     * estoque em produto existente é sempre via adjustStock() (ability
     * própria, `estoque.adjust`), nunca por aqui.
     */
    private function validated(Request $request, ?int $ignoreId = null, bool $includeStock = true): array
    {
        $rules = [
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:100'],
            'variation' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_new_release' => ['nullable', 'boolean'],
        ];

        $defaults = [
            'category_id' => null,
            'description' => null,
            'brand' => null,
            'model' => null,
            'color' => null,
            'variation' => null,
            'cost_price' => null,
            'discount_percentage' => null,
            'discount_amount' => null,
            'is_active' => true,
            'is_featured' => false,
            'is_new_release' => false,
        ];

        if ($includeStock) {
            $rules['stock'] = ['nullable', 'integer', 'min:0'];
            $defaults['stock'] = 0;
        }

        return array_merge($defaults, $request->validate($rules));
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

    private function createWithGeneratedSku(array $validated, ?string $categoryName): Product
    {
        $attempts = 0;

        do {
            $attempts++;
            $validated['sku'] = $this->skuGenerator->generate([
                'categoria' => $categoryName,
                'produto' => $validated['name'] ?? null,
                'marca' => $validated['brand'] ?? null,
                'modelo' => $validated['model'] ?? null,
                'cor' => $validated['color'] ?? null,
                'variacao' => $validated['variation'] ?? null,
            ]);

            try {
                return Product::create($validated);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempts >= self::MAX_SKU_ATTEMPTS) {
                    throw $exception;
                }
            }
        } while (true);
    }
}

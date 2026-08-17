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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    private const MAX_SKU_ATTEMPTS = 5;

    public function __construct(
        private readonly StockManager $stock,
        private readonly MarketplaceDriverManager $drivers,
        private readonly SkuGeneratorService $skuGenerator,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Products/Index', [
            // withCount('children') pro front poder mostrar "N variações"
            // na linha do pai e recolher os filhos por padrão (pedido
            // explícito 2026-08-17, variações de produto) — sem isso a
            // listagem fica com 3 linhas soltas sem relação visível
            // nenhuma pro mesmo caso que motivou a feature (Ring Light
            // 8"/10" como 2 produtos desconectados).
            'products' => Product::query()->with(['category:id,name', 'parent:id,name'])->withCount('children')->latest()->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        // Colunas além de id/name: pré-preenchem a variação nova
        // (Create.vue) com o que faz sentido herdar do pai — categoria,
        // marca, modelo e descrição costumam ser idênticos entre
        // variações; cor/preço/estoque não (ficam em branco de propósito).
        $parent = $request->integer('parent_product_id')
            ? Product::query()->find($request->integer('parent_product_id'), ['id', 'name', 'category_id', 'brand', 'model', 'description'])
            : null;

        return Inertia::render('Admin/Products/Create', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'parent' => $parent,
        ]);
    }

    /**
     * Prévia do SKU em tempo real, chamada pelo front (ProductForm.vue)
     * assim que o usuário para de digitar categoria/nome/marca/modelo/cor/
     * variação — mesmo SkuGeneratorService::generate() usado de verdade em
     * store()/update(), só que sem criar nada no banco (não reserva o
     * número de sequência: um "PROD-0007" mostrado aqui pode não ser o
     * SKU final se outro produto for salvo primeiro, o real só é fixado
     * ao salvar de verdade — igual ao próprio texto do campo já avisa).
     */
    public function previewSku(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:100'],
            'variation' => ['nullable', 'string', 'max:100'],
        ]);

        $category = $validated['category_id'] ?? null
            ? Category::query()->find($validated['category_id'])
            : null;

        return response()->json([
            'sku' => $this->skuGenerator->generate($this->skuPayload($validated, $category?->name)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        // Pedido explícito 2026-08-17 (variações de produto): cadastro de
        // uma variação nova reusa a tela de criação inteira (Create.vue
        // com ?parent_product_id= na URL) — só precisa aceitar e gravar
        // esse vínculo, nenhuma outra mudança no fluxo (SKU/slug/estoque
        // continuam gerados exatamente como pra um produto standalone).
        // ignoreId nulo de propósito: parent_product_id só existe em
        // store() (create), nunca teria outro produto pra ignorar aqui.
        $validated['parent_product_id'] = $request->validate([
            'parent_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
        ])['parent_product_id'] ?? null;

        $validated['slug'] = $this->uniqueSlug($validated['name']);

        $category = $validated['category_id']
            ? Category::query()->find($validated['category_id'])
            : null;

        $product = $this->createWithGeneratedSku($validated, $category?->name);

        return redirect()
            ->route('admin.produtos.editar', $product)
            ->with('success', 'Produto criado com sucesso. Agora complete os dados fiscais, fotos, vídeo e canais de venda.');
    }

    public function edit(Product $product): Response
    {
        // Pedido explícito 2026-08-17 (variações de produto): aba
        // "Variações" precisa saber se este produto É uma variação (pai +
        // IRMÃOS, não os próprios filhos dele — árvore é de só 2 níveis,
        // attachVariation() já garante que um filho nunca tem filho) ou
        // se É um pai/standalone (filhos, se houver). Reusa
        // Product::images() já existente pra miniatura de cada
        // variação/irmão — mesma relação de sempre, sem tabela nova.
        // 'orphans' só carrega quando faz sentido oferecer "vincular
        // produto existente" (produto sem filhos e sem pai — não dá pra
        // vincular uma variação já vinculada em outro lugar, nem virar
        // filho de um produto que já é ele mesmo filho de outro).
        $siblingsQuery = fn () => Product::query()
            ->where('parent_product_id', $product->parent_product_id ?? $product->id)
            ->whereKeyNot($product->id)
            ->with('images:id,product_id,path,is_primary')
            ->get(['id', 'name', 'sku', 'variation', 'stock']);

        $variationsPayload = [
            'parent' => $product->parent()->select(['id', 'name', 'sku'])->first(),
            'siblings' => $siblingsQuery(),
        ];

        $orphans = [];

        if (! $product->parent_product_id && $product->children()->doesntExist()) {
            $orphans = Product::query()
                ->whereKeyNot($product->id)
                ->whereNull('parent_product_id')
                ->whereDoesntHave('children')
                ->orderBy('name')
                ->get(['id', 'name', 'sku']);
        }

        return Inertia::render('Admin/Products/Edit', [
            'product' => $product,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'fiscalData' => $product->fiscalData,
            'images' => $product->images,
            'channels' => $this->drivers->channels(),
            'channelListings' => $product->channelListings,
            'stockMovements' => $product->stockMovements()->latest()->limit(10)->get(),
            'quantityDiscounts' => $product->quantityDiscounts,
            'variations' => $variationsPayload,
            'linkableOrphans' => $orphans,
        ]);
    }

    /**
     * BUG REAL 2026-08-17 ("atualizando produtos... alterando o número de
     * estoque sem querer"): o campo Estoque desta tela mandava um valor
     * ABSOLUTO pré-carregado quando a página abriu — se uma venda de
     * verdade descontasse o estoque enquanto a tela ficava aberta (edição
     * de foto/preço/descrição demorada, ou aba esquecida aberta), salvar
     * o formulário sobrescrevia o estoque real pelo valor antigo da tela,
     * gerando um "ajuste manual" enorme e não intencional (achados reais
     * ao vivo: -51, -167, -182, -240 unidades numa tacada só, mesmo dia
     * de vendas reais registradas pro mesmo produto). Nenhum valor
     * "congelado" na tela pode voltar a ser tratado como estado atual do
     * estoque.
     *
     * Fix: o campo agora manda um AJUSTE (stock_adjustment, +/-, default
     * 0) em vez de um valor absoluto — campo não tocado = 0 = nenhuma
     * mudança, sempre, não importa há quanto tempo a página foi
     * carregada. Aplicado direto sobre o estoque ATUAL do banco (ver
     * abaixo), nunca contra um valor antigo comparado aqui.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validated($request, $product->id, includeStock: false);

        $adjustment = (int) ($request->validate([
            'stock_adjustment' => ['nullable', 'integer'],
        ])['stock_adjustment'] ?? 0);

        // Validado à parte do resto (não no validated() compartilhado com
        // store()): lá o campo é sempre sobrescrito por
        // createWithGeneratedSku() e ignorado, então uma checagem de
        // unicidade ali só arriscaria rejeitar por engano um valor de
        // prévia que colidiu com um SKU real criado por outra requisição
        // enquanto o usuário digitava — algo que o próprio
        // createWithGeneratedSku() já resolve sozinho, gerando outro.
        // Aqui em update() é o valor de verdade que será salvo, então a
        // checagem de unicidade importa de verdade.
        $validated['sku'] = $request->validate([
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
        ])['sku'];

        if ($validated['name'] !== $product->name) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $product->id);
        }

        // Pedido explícito 2026-08-10: SKU passa a ser editável na tela de
        // edição (campo + botão "Gerar novo" que só sugere um valor no
        // front, via previewSku() — quem grava de verdade é este update()
        // normal). Um envio em branco NUNCA apaga o SKU existente (só
        // preenche automaticamente se o produto de fato não tinha nenhum
        // ainda, legado) — o usuário limpar o campo sem querer não pode
        // resultar num produto sem SKU.
        if (blank($validated['sku'] ?? null)) {
            if (blank($product->sku)) {
                $category = $validated['category_id']
                    ? Category::query()->find($validated['category_id'])
                    : null;

                $validated['sku'] = $this->skuGenerator->generate($this->skuPayload($validated, $category?->name));
            } else {
                unset($validated['sku']);
            }
        } else {
            $validated['sku'] = strtoupper(trim($validated['sku']));
        }

        $product->update($validated);

        // $product->stock aqui já é o valor ATUAL do banco (route model
        // binding busca fresco no início desta requisição, e $validated
        // não inclui 'stock' — update() acima não o toca) — soma direto
        // sobre ele, nunca contra um valor absoluto vindo do front.
        if ($adjustment !== 0) {
            $this->stock->adjust($product, $adjustment, StockMovement::TYPE_ADJUSTMENT, reason: 'Ajuste manual no cadastro do produto');
        }

        return redirect()->route('admin.produtos.listar')->with('success', 'Produto atualizado com sucesso.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return back()->with('success', 'Produto removido com sucesso.');
    }

    /**
     * Vincula um produto EXISTENTE e órfão (sem pai, sem filho) como
     * variação deste — pedido explícito 2026-08-17, resolve o caso real
     * que motivou a feature: um 2º anúncio Shopee duplicado pro mesmo item
     * físico gerou um produto novo desconectado (sem foto, SKU parecido
     * mas diferente) em vez de virar variação do já existente. Guard
     * contra árvore de 2 níveis (variação de variação) — o alvo não pode
     * já ter pai nem já ter filho, e não pode ser o próprio produto.
     */
    public function attachVariation(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'variation_product_id' => ['required', 'integer', Rule::exists('products', 'id')],
        ]);

        $variation = Product::query()->findOrFail($validated['variation_product_id']);

        if ($variation->is($product) || $product->parent_product_id) {
            return back()->with('error', 'Não é possível vincular esse produto como variação.');
        }

        if ($variation->parent_product_id || $variation->children()->exists()) {
            return back()->with('error', 'Esse produto já faz parte de outro grupo de variações.');
        }

        $variation->update(['parent_product_id' => $product->id]);

        return back()->with('success', "\"{$variation->name}\" vinculado como variação de \"{$product->name}\".");
    }

    /**
     * Desfaz o vínculo — a variação volta a ser um produto standalone
     * comum (nada nela muda além do parent_product_id, ela já tinha
     * SKU/estoque/fotos próprios o tempo todo).
     */
    public function detachVariation(Product $product): RedirectResponse
    {
        $product->update(['parent_product_id' => null]);

        return back()->with('success', "\"{$product->name}\" desvinculado — voltou a ser um produto independente.");
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

    /**
     * $includeStock=false pro fluxo de update() desde 2026-08-17 (ver
     * comentário completo em update()) — o estoque na edição passou a ser
     * um AJUSTE separado (stock_adjustment), não mais um valor absoluto
     * validado/aplicado aqui junto com o resto do formulário.
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
            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_new_release' => ['boolean'],
        ];

        if ($includeStock) {
            $rules['stock'] = ['required', 'integer', 'min:0'];
        }

        return $request->validate($rules);
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

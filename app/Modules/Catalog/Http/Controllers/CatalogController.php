<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesShopeeAuthorizationLanding;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Operacional\Models\ShippingMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    use HandlesShopeeAuthorizationLanding;

    private const PRODUCT_PAGE_VERSION = 2;

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
        $categoria = (string) $request->query('categoria', '');

        $baseQuery = Product::query()
            ->forCard()
            ->where('is_active', true)
            // Variações continuam com página própria e compra própria, mas
            // não podem aparecer como cards soltos na vitrine: o cliente
            // precisa ver um anúncio/produto e escolher a variação dentro
            // dele, estilo Shopee/Mercado Livre.
            ->whereNull('parent_product_id')
            ->when($search->isNotEmpty(), fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($tipo === 'destaque', fn ($query) => $query->where('is_featured', true))
            ->when($tipo === 'lancamento', fn ($query) => $query->where('is_new_release', true))
            // Departamentos (pedido 2026-10-10): clicar no círculo filtra a vitrine.
            ->when($categoria !== '', fn ($query) => $query->whereHas('category', fn ($c) => $c->where('slug', $categoria)));

        $products = (clone $baseQuery)
            ->latest()
            // 8 por vez; o "Carregar mais" da home busca a próxima página só
            // com essa prop (pedido 2026-10-10).
            ->paginate(8)
            ->withQueryString();

        return Inertia::render('Catalog/Home', [
            'banners' => Banner::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'title', 'image_path', 'image_path_mobile', 'link_url']),
            'featuredProducts' => Product::query()
                ->forCard()
                ->where('is_active', true)
                ->whereNull('parent_product_id')
                ->where('is_featured', true)
                ->latest()
                ->take(5)
                ->get(),
            'products' => $products,
            'categories' => $this->departamentos(),
            'favoriteIds' => $request->user()
                ? Favorite::query()->where('user_id', $request->user()->id)->pluck('product_id')
                : [],
            'reviewableProductIds' => $this->reviewableProductIds($request),
            'reviewedProductIds' => $request->user()
                ? Review::query()->where('user_id', $request->user()->id)->pluck('product_id')
                : [],
            'filters' => $request->only('search', 'tipo', 'categoria'),
        ]);
    }

    /**
     * Departamentos da home (pedido 2026-10-10): círculo com imagem. Sem foto
     * cadastrada na categoria, usa a foto de um produto dela. Fica 10 min em
     * cache — a lista muda pouco e a home tem que abrir rápido.
     *
     * @return list<array{id: int, name: string, slug: string, image_url: ?string}>
     */
    private function departamentos(): array
    {
        return Cache::remember('loja:departamentos', now()->addMinutes(10), function () {
            $ativos = fn ($query) => $query->where('is_active', true)->whereNull('parent_product_id');

            return Category::query()
                ->whereHas('products', $ativos)
                ->withCount(['products' => $ativos])
                ->orderByDesc('products_count')
                ->get(['id', 'name', 'slug', 'image_path'])
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'image_url' => $category->image_url ?? ProductImage::query()
                        ->whereHas('product', fn ($query) => $ativos($query)->where('category_id', $category->id))
                        ->orderByDesc('is_primary')->orderBy('position')
                        ->first()?->thumb_url,
                ])
                ->all();
        });
    }

    public function show(Request $request, Product $product): Response
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'category:id,name,slug',
            'images' => fn ($query) => $query->orderBy('position'),
            'quantityDiscounts',
            // Benefícios + descrição em blocos (pedido 2026-10-09).
            'adContent',
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
            ->forCard()
            ->where('is_active', true)
            ->whereNull('parent_product_id')
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($query) => $query->where('category_id', $product->category_id))
            ->inRandomOrder()
            ->take(5)
            ->get();

        if ($relatedProducts->count() < 5) {
            $excludeIds = $relatedProducts->pluck('id')->push($product->id);

            $relatedProducts = $relatedProducts->concat(
                Product::query()
                    ->forCard()
                    ->where('is_active', true)
                    ->whereNull('parent_product_id')
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

        // Página de produto v2 (layout de loja com box de compra fixo, pedido
        // 2026-10-09). A v1 continua no código: ?v=1 mostra a antiga, e pra
        // voltar de vez é só trocar PRODUCT_PAGE_VERSION para 1.
        $version = in_array($request->query('v'), ['1', '2'], true)
            ? (int) $request->query('v')
            : self::PRODUCT_PAGE_VERSION;

        return Inertia::render($version === 2 ? 'Catalog/ProductDetailV2' : 'Catalog/ProductDetail', [
            'product' => $product,
            'variations' => $variations,
            'reviews' => $reviews,
            'shippingMethods' => ShippingMethod::query()
                ->where('is_active', true)
                ->orderBy('price')
                ->get(['id', 'name', 'estimated_days', 'price'])
                ->map(fn (ShippingMethod $method) => [
                    'id' => $method->id,
                    'name' => $method->name,
                    'estimated_days' => (int) $method->estimated_days,
                    'price' => 0.0,
                    'actual_price' => (float) $method->price,
                    'free_shipping' => true,
                ]),
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

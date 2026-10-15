<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesShopeeAuthorizationLanding;
use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Favorite;
use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\OfertaRelampago;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Support\VitrineHome;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Operacional\Models\ShippingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // Catálogo da busca/aba, sem departamento: base da contagem por
        // departamento da coluna de filtros (pedido 2026-10-15).
        $catalogo = Product::query()
            ->where('is_active', true)
            ->comEstoque()
            // Variações continuam com página própria e compra própria, mas
            // não podem aparecer como cards soltos na vitrine: o cliente
            // precisa ver um anúncio/produto e escolher a variação dentro
            // dele, estilo Shopee/Mercado Livre.
            ->whereNull('parent_product_id')
            ->when($search->isNotEmpty(), fn ($query) => self::filtrarBusca($query, (string) $search))
            ->when($tipo === 'destaque', fn ($query) => $query->where('is_featured', true))
            ->when($tipo === 'lancamento', fn ($query) => $query->where('is_new_release', true));

        $baseQuery = (clone $catalogo)
            ->forCard()
            // Departamentos (pedido 2026-10-10): clicar no círculo filtra a vitrine.
            ->when($categoria !== '', fn ($query) => $query->whereHas('category', fn ($c) => $c->where('slug', $categoria)));

        // Preço e desconto da coluna de filtros (pedido 2026-10-15): valem o
        // preço e o "X% OFF" que o card mostra (com Oferta do Dia, Relâmpago e
        // frete embutido), que só dá para calcular aqui. O catálogo é pequeno,
        // então confere produto a produto e a lista filtra pelos ids.
        $faixa = self::FAIXAS_PRECO[(string) $request->query('preco', '')] ?? null;
        $desconto = (int) $request->query('desconto', 0);
        $filtraPreco = $faixa !== null || $desconto > 0;
        $passam = null;
        $produtosQuePassam = function () use (&$passam, $catalogo, $faixa, $desconto) {
            return $passam ??= (clone $catalogo)->get()
                ->filter(fn (Product $produto) => ($faixa === null || ($produto->final_price >= $faixa[0] && $produto->final_price < $faixa[1]))
                    && ($desconto <= 0 || self::descontoDoCard($produto) >= $desconto))
                ->values();
        };
        if ($filtraPreco) {
            $baseQuery->whereIn('id', $produtosQuePassam()->pluck('id'));
        }

        // Ordenar (pedido 2026-10-10). Preço = o que o cliente paga (já com desconto).
        $ordenar = (string) $request->query('ordenar', '');
        // Desde 2026-10-15 pelo preço do card (com oferta e frete embutido),
        // como o filtro de preço: pelo cadastro a ordem saía trocada na tela.
        if (in_array($ordenar, ['menor_preco', 'maior_preco'], true)) {
            $porPreco = $produtosQuePassam()->sortBy(fn (Product $produto) => $produto->final_price, SORT_REGULAR, $ordenar === 'maior_preco')->pluck('id')->values();
            if ($porPreco->isNotEmpty()) {
                $baseQuery->orderByRaw('CASE id '.$porPreco->map(fn () => 'WHEN ? THEN ?')->implode(' ').' END',
                    $porPreco->flatMap(fn (int $id, int $posicao) => [$id, $posicao])->all());
            }
        }
        $baseQuery
            ->when($ordenar === 'nome', fn ($query) => $query->orderBy('name'))
            // Na busca, quem começa com o termo vem primeiro.
            ->when($ordenar === '' && $search->isNotEmpty(), fn ($query) => $query->orderByRaw(
                'CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END',
                [$search.'%', '%'.$search.'%'],
            ));

        // Primeira leva com 8 e depois de 12 em 12 (pedido 2026-10-13): a
        // rolagem infinita da home busca a próxima página só com essa prop.
        $products = function () use ($baseQuery, $request) {
            $pagina = max(1, (int) $request->query('page', 1));
            $total = (clone $baseQuery)->toBase()->getCountForPagination();

            // Mesmo formato do paginate() nos campos que a tela usa; o
            // paginate() não serve porque supõe páginas do mesmo tamanho.
            return [
                'data' => (clone $baseQuery)
                    ->latest()
                    ->skip($pagina === 1 ? 0 : 8 + ($pagina - 2) * 12)
                    ->take($pagina === 1 ? 8 : 12)
                    ->get(),
                'current_page' => $pagina,
                'last_page' => $total <= 8 ? 1 : 1 + (int) ceil(($total - 8) / 12),
                'total' => $total,
            ];
        };

        // Sem busca/filtro a home é a vitrine (seções); com filtro, a lista com "Carregar mais".
        $modoLista = $search->isNotEmpty() || $tipo || $categoria !== '' || $filtraPreco || $request->boolean('todos');

        // Coluna de filtros: departamentos com quantos produtos cada um tem
        // na busca/aba e no preço/desconto escolhidos (o escolhido fica mesmo
        // com 0, para dar para desmarcar).
        $departamentosFiltro = function () use ($catalogo, $filtraPreco, $produtosQuePassam, $categoria) {
            $totais = $filtraPreco
                ? $produtosQuePassam()->countBy('category_id')->all()
                : (clone $catalogo)->toBase()->selectRaw('category_id, count(*) as total')->groupBy('category_id')->pluck('total', 'category_id')->all();

            return collect($this->departamentos())
                ->map(fn (array $dep) => ['slug' => $dep['slug'], 'name' => $dep['name'], 'total' => (int) ($totais[$dep['id']] ?? 0)])
                ->filter(fn (array $dep) => $dep['total'] > 0 || $dep['slug'] === $categoria)
                ->sortBy('name')
                ->values()
                ->all();
        };

        return Inertia::render('Catalog/Home', [
            'vitrine' => $modoLista ? null : fn () => app(VitrineHome::class)->montar(),
            'banners' => Banner::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'title', 'image_path', 'image_path_mobile', 'link_url']),
            'products' => $modoLista ? $products : null,
            'categories' => $this->departamentos(),
            // Na busca, sem departamentos à mostra (pedido 2026-10-15).
            'departamentosFiltro' => $modoLista && $search->isEmpty() ? $departamentosFiltro : [],
            'favoriteIds' => $request->user()
                ? Favorite::query()->where('user_id', $request->user()->id)->pluck('product_id')
                : [],
            'reviewableProductIds' => $this->reviewableProductIds($request),
            'reviewedProductIds' => $request->user()
                ? Review::query()->where('user_id', $request->user()->id)->pluck('product_id')
                : [],
            'filters' => $request->only('search', 'tipo', 'categoria', 'todos', 'ordenar', 'preco', 'desconto'),
        ]);
    }

    /**
     * Busca instantânea do topo (pedido 2026-10-15, como na Beauty Kora): ao
     * digitar aparece a lista com foto e nome. Mesma regra e ordem da busca
     * da lista; só os campos que a lista usa, para responder rápido.
     */
    public function sugestoes(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        if (mb_strlen($search) < 2) {
            return response()->json(['produtos' => [], 'total' => 0]);
        }

        $query = Product::query()
            ->where('is_active', true)
            ->comEstoque()
            ->whereNull('parent_product_id');
        self::filtrarBusca($query, mb_substr($search, 0, 100));

        $total = (clone $query)->count();
        $produtos = $query
            ->select(['id', 'parent_product_id', 'name', 'slug', 'price', 'discount_percentage', 'discount_amount'])
            ->with(['images' => fn ($images) => $images->select(['id', 'product_id', 'path', 'thumb_path', 'position', 'is_primary'])
                ->reorder()->orderByDesc('is_primary')->orderBy('position')->limit(1)])
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$search.'%', '%'.$search.'%'])
            ->latest()
            ->take(8)
            ->get()
            ->map(fn (Product $produto) => [
                'nome' => $produto->name,
                'slug' => $produto->slug,
                'imagem' => $produto->images->first()?->thumb_url ?? $produto->images->first()?->url,
                'preco' => $produto->final_price,
            ]);

        return response()->json(['produtos' => $produtos, 'total' => $total]);
    }

    /** Busca (pedido 2026-10-10): cada palavra no nome, marca, modelo, SKU ou departamento, em qualquer ordem. */
    private static function filtrarBusca($query, string $search): void
    {
        foreach (preg_split('/\s+/', trim($search)) as $palavra) {
            $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $palavra).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $termo)
                ->orWhere('brand', 'like', $termo)
                ->orWhere('model', 'like', $termo)
                ->orWhere('sku', 'like', $termo)
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $termo)));
        }
    }

    /** O "X% OFF" do card (ProductCard.vue): preço final contra o riscado. */
    private static function descontoDoCard(Product $produto): int
    {
        $antes = $produto->emOferta()
            ? (float) $produto->preco_sem_oferta
            : ($produto->has_discount ? (float) ($produto->preco_de ?? $produto->price) : 0.0);

        return $antes > 0 ? max(1, (int) round((1 - $produto->final_price / $antes) * 100)) : 0;
    }

    /** Faixas de preço dos filtros (ofertas, departamentos e busca): chave => [mínimo, máximo). */
    private const FAIXAS_PRECO = [
        'ate-50' => [0, 50],
        '50-100' => [50, 100],
        '100-200' => [100, 200],
        '200-mais' => [200, PHP_INT_MAX],
    ];

    /**
     * Página de ofertas (pedido 2026-10-15, layout de filtros da Beauty
     * Kora): Ofertas Relâmpago no ar agora e Ofertas do Dia de hoje. São
     * poucas, então filtro e ordem rodam aqui mesmo, sem paginação. Tudo fica
     * na URL: departamento, desconto mínimo, faixa de preço, "termina em
     * breve" (menos de 2 h) e ordem (padrão: maior desconto).
     */
    public function ofertas(Request $request): Response
    {
        $ids = array_unique([...array_keys(OfertaDoDia::precosDeHoje()), ...array_keys(OfertaRelampago::ativasAgora())]);
        $agora = now();

        $todas = Product::query()
            ->forCard()
            ->where('is_active', true)
            ->comEstoque()
            ->whereNull('parent_product_id')
            ->whereIn('id', $ids)
            ->with('category:id,name,slug')
            ->get()
            ->filter(fn (Product $produto) => $produto->emOferta())
            ->each(function (Product $produto) use ($agora) {
                $preco = (float) $produto->final_price;
                $de = (float) $produto->preco_sem_oferta;
                $relampago = $produto->relampagoNoAr();
                $doDia = $produto->precoOfertaDeHoje();
                // Termina quando acaba a oferta que dá o preço de agora.
                $fim = $relampago && ($doDia === null || $produto->precoRelampago() <= $doDia)
                    ? $relampago->fim_em
                    : $agora->copy()->endOfDay();

                $produto->setAttribute('desconto_oferta', $de > 0 ? (int) round((1 - $preco / $de) * 100) : 0);
                $produto->setAttribute('oferta_termina_em', $fim->toIso8601String());
            });

        $categoria = (string) $request->query('categoria', '');
        $desconto = (int) $request->query('desconto', 0);
        $faixa = self::FAIXAS_PRECO[(string) $request->query('preco', '')] ?? null;
        $acabando = $request->boolean('acabando');
        $ordenar = (string) $request->query('ordenar', '');

        // Filtros, menos o departamento: a contagem de cada departamento já
        // considera desconto, preço e "termina em breve".
        $filtradas = $todas
            ->when($desconto > 0, fn ($lista) => $lista->filter(fn (Product $p) => $p->desconto_oferta >= $desconto))
            ->when($faixa, fn ($lista) => $lista->filter(fn (Product $p) => $p->final_price >= $faixa[0] && $p->final_price < $faixa[1]))
            ->when($acabando, fn ($lista) => $lista->filter(fn (Product $p) => Carbon::parse($p->oferta_termina_em)->lte($agora->copy()->addHours(2))));

        $departamentos = $filtradas
            ->filter(fn (Product $p) => $p->category)
            ->groupBy(fn (Product $p) => $p->category->slug)
            ->map(fn ($lista) => ['slug' => $lista->first()->category->slug, 'name' => $lista->first()->category->name, 'total' => $lista->count()])
            ->sortBy('name')
            ->values();

        $produtos = $filtradas
            ->when($categoria !== '', fn ($lista) => $lista->filter(fn (Product $p) => $p->category?->slug === $categoria))
            ->sortBy(match ($ordenar) {
                'termina' => [['oferta_termina_em', 'asc'], ['desconto_oferta', 'desc']],
                'menor_preco' => [['final_price', 'asc']],
                'maior_preco' => [['final_price', 'desc']],
                default => [['desconto_oferta', 'desc'], ['final_price', 'asc']],
            })
            ->values();

        $ids = $produtos->pluck('id');

        return Inertia::render('Catalog/Ofertas', [
            'produtos' => $produtos,
            'totalOfertas' => $todas->count(),
            'departamentos' => $departamentos,
            'filters' => $request->only('categoria', 'desconto', 'preco', 'acabando', 'ordenar'),
            'favoriteIds' => $request->user()
                ? Favorite::query()->where('user_id', $request->user()->id)->whereIn('product_id', $ids)->pluck('product_id')
                : [],
            'reviewableProductIds' => $this->reviewableProductIds($request),
            'reviewedProductIds' => $request->user()
                ? Review::query()->where('user_id', $request->user()->id)->whereIn('product_id', $ids)->pluck('product_id')
                : [],
        ]);
    }

    /** Departamentos da home — os mesmos do mega menu (ver MenuDaLoja). */
    private function departamentos(): array
    {
        return \App\Modules\Catalog\Support\MenuDaLoja::departamentos();
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

        // Vendidos (pedido 2026-10-10): pedidos pagos, enviados ou entregues de
        // todos os canais (loja e marketplaces), somando as variações.
        $salesCount = OrderItem::query()
            ->whereIn('product_id', Product::query()->where('id', $product->id)->orWhere('parent_product_id', $product->id)->select('id'))
            ->whereHas('order', fn ($query) => $query->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED]))
            ->sum('quantity');

        // Avaliações (pedido 2026-10-11): do produto E das variações dele (a
        // avaliação importada fica na variação vendida — Power Bank Rosa…).
        // Ordem: mais estrelas primeiro; dentro da mesma nota, com foto/vídeo
        // antes (mais fotos primeiro); depois as mais recentes. A última é a
        // de 1 estrela. A tela mostra 20 e carrega o resto de 12 em 12.
        $reviews = Review::query()
            ->whereIn('product_id', Product::query()->where('id', $product->id)->orWhere('parent_product_id', $product->id)->select('id'))
            ->with(['user:id,name', 'images'])
            ->withCount('images')
            ->orderByDesc('rating')
            ->orderByDesc('images_count')
            ->latest()
            ->get();

        // Nota e contagem do topo batendo com a lista (variações incluídas).
        $product->setAttribute('reviews_count', $reviews->count());
        $product->setAttribute('reviews_avg_rating', $reviews->count() ? round((float) $reviews->avg('rating'), 4) : null);

        $user = $request->user();

        $relatedProducts = Product::query()
            ->forCard()
            ->where('is_active', true)
            ->comEstoque()
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
                    ->comEstoque()
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

        // Galeria única do grupo de variações (pedido 2026-10-15): todas as
        // variações mostram as mesmas fotos, na mesma ordem (as do produto
        // pai, depois a foto principal de cada variação que o pai ainda não
        // tem). Escolher a variação só destaca a foto dela (`variant_id`),
        // sem reordenar. Produto sem variação segue com as próprias fotos.
        $galeria = null;
        if ($variations->isNotEmpty()) {
            $parentId = $product->parent_product_id ?? $product->id;
            $pai = $parentId === $product->id
                ? $product
                : Product::query()->with(['images' => fn ($query) => $query->orderBy('position')])->find($parentId);

            if ($pai) {
                $principalPai = $pai->images->firstWhere('is_primary', true) ?? $pai->images->first();
                $galeria = $pai->images->map(fn ($image) => [
                    'url' => $image->url,
                    'variant_id' => $principalPai && $image->id === $principalPai->id ? $pai->id : null,
                ])->all();

                // [id => foto principal] de todas as variações, inclusive a da página.
                $irmas = $variations->mapWithKeys(fn ($v) => [$v->id => $v->images->firstWhere('is_primary', true) ?? $v->images->first()]);
                $irmas->put($product->id, $product->images->firstWhere('is_primary', true) ?? $product->images->first());

                foreach ($irmas->sortKeys() as $irmaId => $imagem) {
                    if ($irmaId === $pai->id || ! $imagem || collect($galeria)->contains('url', $imagem->url)) {
                        continue;
                    }
                    $galeria[] = ['url' => $imagem->url, 'variant_id' => $irmaId];
                }
            }
        }

        // Página de produto v2 (layout de loja com box de compra fixo, pedido
        // 2026-10-09). A v1 continua no código: ?v=1 mostra a antiga, e pra
        // voltar de vez é só trocar PRODUCT_PAGE_VERSION para 1.
        $version = in_array($request->query('v'), ['1', '2'], true)
            ? (int) $request->query('v')
            : self::PRODUCT_PAGE_VERSION;

        return Inertia::render($version === 2 ? 'Catalog/ProductDetailV2' : 'Catalog/ProductDetail', [
            // Custo é dado interno: não vai no JSON da página pública.
            'product' => $product->makeHidden('cost_price'),
            'variations' => $variations,
            'galeria' => $galeria,
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

<?php

namespace App\Modules\App\Services;

use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Support\MenuDaLoja;
use App\Modules\Catalog\Support\VitrineHome;
use App\Modules\Checkout\Services\CheckoutOutroSite;
use App\Support\DescontoPix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Catálogo do app nativo da KazaKora (2026-10-18), com os mesmos produtos,
 * preços e vitrines das telas da loja: CatalogController (busca, ordem),
 * VitrineHome (seções da home), MenuDaLoja (departamentos) e o card
 * (ProductCard.vue): preço da variação mais barata, com frete embutido,
 * Oferta do Dia e Relâmpago. Valores em centavos (contrato do app).
 *
 * Os preços dependem do FreteEmbutido ligado e do SiteAtual da KazaKora
 * (middleware PrepararLojaDoApp).
 */
class CatalogoApp
{
    public const ORDENS = ['relevancia', 'menor_preco', 'maior_preco', 'novidades', 'mais_vendidos'];

    /** Banners e seções da home, na ordem da home do site. */
    public function inicio(): array
    {
        $vitrine = app(VitrineHome::class)->montar();
        $secoes = [
            ['titulo' => 'Ofertas do Dia', 'slug' => 'ofertas-do-dia', 'produtos' => $vitrine['ofertas']],
            ['titulo' => 'Mais buscados da semana', 'slug' => 'mais-buscados', 'produtos' => $vitrine['maisBuscados']],
            ['titulo' => 'Utilidades para casa', 'slug' => 'utilidades', 'produtos' => $vitrine['utilidades'], 'departamento_slug' => $vitrine['utilidadesLink']],
            ['titulo' => 'Últimas novidades', 'slug' => 'novidades', 'produtos' => $vitrine['novidades']],
            ['titulo' => 'Você também pode gostar', 'slug' => 'voce-tambem-pode-gostar', 'produtos' => $vitrine['gostar']],
        ];

        return [
            'banners' => Banner::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Banner $banner) => $this->banner($banner))->values()->all(),
            'vitrines' => collect($secoes)
                ->map(fn (array $secao) => [
                    'titulo' => $secao['titulo'],
                    'slug' => $secao['slug'],
                    'departamento_slug' => $secao['departamento_slug'] ?? null,
                    'termina_em' => $secao['slug'] === 'ofertas-do-dia' ? $vitrine['ofertasTerminamEm'] : null,
                    'produtos' => collect($secao['produtos'])->map(fn (Product $produto) => $this->card($produto))->values()->all(),
                ])
                ->filter(fn (array $secao) => $secao['produtos'] !== [])
                ->values()
                ->all(),
        ];
    }

    /** Departamentos do mega menu da loja (lista plana: a loja não tem subcategoria). */
    public function departamentos(): array
    {
        return collect(MenuDaLoja::departamentos())
            ->map(fn (array $dep) => [
                'id' => $dep['id'],
                'nome' => $dep['name'],
                'slug' => $dep['slug'],
                'imagem' => $dep['image_url'],
                'total' => $dep['total'],
                'filhos' => [],
            ])
            ->values()
            ->all();
    }

    /**
     * Lista com busca, departamento e ordem, paginada. Mesma regra da lista
     * da home do site: só anúncio (pai) ativo e com estoque.
     *
     * @return array{produtos: list<array<string, mixed>>, pagina: int, por_pagina: int, total: int, tem_mais: bool}
     */
    public function produtos(?string $busca, ?string $departamento, string $ordem, int $pagina, int $porPagina): array
    {
        $busca = trim((string) $busca);
        $base = Product::query()
            ->where('is_active', true)
            ->comEstoque()
            ->whereNull('parent_product_id')
            ->when($busca !== '', fn (Builder $q) => CatalogController::filtrarBusca($q, mb_substr($busca, 0, 100)))
            ->when(filled($departamento), fn (Builder $q) => $q->whereHas('category', fn ($c) => $c->where('slug', $departamento)));

        $total = (clone $base)->count();

        if (in_array($ordem, ['menor_preco', 'maior_preco'], true)) {
            // Pelo preço do card (oferta + frete embutido), como o site faz.
            $ids = (clone $base)->comPrecoDaVariacao()->get()
                ->sortBy(fn (Product $produto) => $produto->variacaoMaisBarata()->final_price, SORT_REGULAR, $ordem === 'maior_preco')
                ->pluck('id')->slice(($pagina - 1) * $porPagina, $porPagina)->values();
            $porId = Product::query()->forCard()->whereIn('id', $ids)->get()->keyBy('id');
            $produtos = $ids->map(fn ($id) => $porId->get($id))->filter();
        } else {
            $query = (clone $base)->forCard();
            match ($ordem) {
                'mais_vendidos' => $query->orderByDesc('vendidos')->latest(),
                'novidades' => $query->latest(),
                default => $busca !== ''
                    ? $query->orderByRaw('CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$busca.'%', '%'.$busca.'%'])->latest()
                    : $query->latest(),
            };
            $produtos = $query->skip(($pagina - 1) * $porPagina)->take($porPagina)->get();
        }

        return [
            'produtos' => $produtos->map(fn (Product $produto) => $this->card($produto))->values()->all(),
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $total,
            'tem_mais' => $pagina * $porPagina < $total,
        ];
    }

    /** Produto pelo slug do anúncio ou de uma variação dele (vale sempre o anúncio). null = fora da loja. */
    public function produto(string $slug): ?array
    {
        $achado = Product::query()->where('slug', $slug)->where('is_active', true)->first();
        $pai = $achado?->parent_product_id ? Product::query()->where('is_active', true)->find($achado->parent_product_id) : $achado;

        if (! $pai) {
            return null;
        }

        $pai->load([
            'category:id,name,slug',
            'images' => fn ($q) => $q->orderBy('position'),
            'variacoesAtivas',
            'children' => fn ($q) => $q->where('is_active', true)->orderBy('id')->with(['images' => fn ($i) => $i->orderBy('position'), 'quantityDiscounts']),
        ]);

        /** @var Collection<int, Product> $grupo */
        $grupo = collect([$pai])->concat($pai->children)->values();
        $temOpcao = $grupo->count() > 1;
        $opcao = $this->nomeDaOpcao($pai);

        $variantes = $grupo->map(function (Product $produto) use ($pai, $temOpcao, $opcao) {
            $nome = $this->rotulo($produto, $pai);
            [$preco, $de] = $this->precos($produto);

            return [
                'variante' => 'kzv-'.$produto->id,
                'nome' => $nome,
                'atributos' => $temOpcao ? [$opcao => $nome] : (object) [],
                'preco' => $preco,
                'preco_de' => $de,
                'preco_pix' => $this->comPix($preco, $produto),
                'estoque' => max(0, (int) $produto->stock),
                'imagem' => $this->fotoDaVariacao($produto)?->url ?? $pai->images->first()?->url,
            ];
        })->values();

        // Galeria única do grupo, como a página do produto: fotos do anúncio e
        // depois a de cada variação que ainda não está nelas.
        $imagens = $pai->images->map(fn ($imagem) => $imagem->url)
            ->concat($grupo->skip(1)->map(fn (Product $produto) => $this->fotoDaVariacao($produto)?->url))
            ->filter()->unique()->values()->all();

        return [
            ...$this->card($pai),
            'imagens' => $imagens,
            'descricao_html' => $this->descricaoHtml((string) $pai->description),
            'departamento' => $pai->category ? ['id' => $pai->category->id, 'nome' => $pai->category->name, 'slug' => $pai->category->slug] : null,
            'opcoes' => $temOpcao ? [['nome' => $opcao, 'valores' => $variantes->pluck('nome')->unique()->values()->all()]] : [],
            'variantes' => $variantes->all(),
            'avaliacoes' => $this->avaliacoes($pai),
        ];
    }

    /**
     * Avaliações do anúncio e das variações, na ordem da página do produto
     * (CatalogController::show): mais estrelas, com foto antes, mais recentes.
     * Média e total de todas; a lista vai até 20, como a primeira carga do site.
     *
     * @return array{media: ?float, total: int, itens: list<array<string, mixed>>}
     */
    private function avaliacoes(Product $pai): array
    {
        $reviews = Review::query()
            ->whereIn('product_id', Product::query()->where('id', $pai->id)->orWhere('parent_product_id', $pai->id)->select('id'))
            ->with(['user:id,name', 'images'])
            ->withCount('images')
            ->orderByDesc('rating')
            ->orderByDesc('images_count')
            ->latest()
            ->get();

        return [
            'media' => $reviews->isNotEmpty() ? round((float) $reviews->avg('rating'), 1) : null,
            'total' => $reviews->count(),
            'itens' => $reviews->take(20)->map(fn (Review $review) => [
                'id' => $review->id,
                'nome' => $review->reviewer_display_name,
                'nota' => (int) $review->rating,
                'comentario' => $review->comment,
                'resposta_loja' => $review->seller_reply,
                'imagens' => $review->images->pluck('image_url')->filter()->values()->all(),
                'criado_em' => $review->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Card do produto (ProductCard.vue): a variação mais barata, como na
     * vitrine. Produto carregado com forCard() ou comPrecoDaVariacao().
     */
    public function card(Product $produto): array
    {
        $produto->loadMissing(['variacoesAtivas', 'images' => fn ($q) => $q->reorder()->orderByDesc('is_primary')->orderBy('position')]);
        $card = $produto->variacaoMaisBarata();
        [$preco, $de] = $this->precos($card);
        $imagem = $produto->images->firstWhere('is_primary', true) ?? $produto->images->first();
        $relampago = $card->relampagoNoAr();

        return [
            'id' => $produto->id,
            'slug' => $produto->slug,
            'nome' => $produto->name,
            'imagem' => $imagem?->thumb_url ?? $imagem?->url,
            'preco' => $preco,
            'preco_de' => $de,
            'preco_pix' => $this->comPix($preco, $card),
            'desconto_percentual' => $de ? CatalogController::descontoDoCard($card) : 0,
            'relampago' => $relampago ? ['termina_em' => $relampago->fim_em->toIso8601String()] : null,
            'disponivel' => $produto->stock > 0 || $produto->variacoesAtivas->contains(fn (Product $v) => $v->stock > 0),
            'link' => rtrim((string) config('app.url'), '/').'/produtos/'.$produto->slug,
        ];
    }

    /** @return array{id: int, imagem: string, imagem_celular: ?string, titulo: ?string, link: ?string, produto_slug: ?string, departamento_slug: ?string} */
    private function banner(Banner $banner): array
    {
        $link = (string) $banner->link_url;
        $caminho = (string) parse_url($link, PHP_URL_PATH);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return [
            'id' => $banner->id,
            'imagem' => $banner->image_url,
            'imagem_celular' => $banner->image_url_mobile,
            'titulo' => $banner->title,
            'link' => $link !== '' ? $link : null,
            // O app abre na própria tela quando o banner leva a um produto ou departamento.
            'produto_slug' => str_starts_with($caminho, '/produtos/') ? Str::after($caminho, '/produtos/') ?: null : null,
            'departamento_slug' => filled($query['categoria'] ?? null) ? (string) $query['categoria'] : null,
        ];
    }

    /**
     * Preço e preço "de" riscado em centavos, como o card: em oferta, riscado
     * o preço sem ela; com desconto cadastrado, o preço "de".
     *
     * @return array{0: int, 1: ?int}
     */
    private function precos(Product $produto): array
    {
        $preco = CheckoutOutroSite::centavos((float) $produto->final_price);
        $de = $produto->emOferta() ? $produto->preco_sem_oferta : $produto->preco_de;
        $de = $de !== null ? CheckoutOutroSite::centavos((float) $de) : null;

        return [$preco, $de !== null && $de > $preco ? $de : null];
    }

    /** Preço de 1 unidade pagando no Pix ("Desconto Checkout" do produto, o mesmo do checkout). */
    private function comPix(int $centavos, Product $produto): int
    {
        return CheckoutOutroSite::centavos(round($centavos / 100 - DescontoPix::desconto($centavos / 100, DescontoPix::percentualDo($produto)), 2));
    }

    private function fotoDaVariacao(Product $produto)
    {
        return $produto->images->firstWhere('is_variant', true)
            ?? $produto->images->firstWhere('is_primary', true)
            ?? $produto->images->first();
    }

    /** "Azul", "Tom 01"; sem variação cadastrada, a cor ou o nome. */
    private function rotulo(Product $produto, Product $pai): string
    {
        return trim((string) ($produto->variation ?: $produto->color ?: $produto->name ?: $pai->name));
    }

    private function nomeDaOpcao(Product $pai): string
    {
        $primeira = Str::lower(Str::before(trim((string) $pai->variation), ' '));

        return match (true) {
            $primeira === 'tom' => 'Tom',
            $primeira === 'cor', blank($pai->variation) && filled($pai->color) => 'Cor',
            default => 'Opção',
        };
    }

    /** Descrição em texto vira um parágrafo por linha; HTML segue como está. */
    private function descricaoHtml(string $texto): string
    {
        if ($texto !== strip_tags($texto)) {
            return $texto;
        }

        return collect(preg_split('/\R/', $texto))
            ->map(fn ($linha) => trim($linha))
            ->filter()
            ->map(fn ($linha) => '<p>'.e($linha).'</p>')
            ->implode('');
    }
}

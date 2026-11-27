<?php

namespace App\Modules\Catalog\Models;


use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Support\Rbac\Auditable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'parent_product_id',
        'categoria_frete',
        'sku',
        'sku_anterior',
        'name',
        'nome_interno',
        'slug',
        'description',
        'brand',
        'model',
        'color',
        'variation',
        'layout_pagina',
        'avaliacoes_de',
        'pagina_brinquedo',
        'escada_kits',
        'cartoes_opcoes',
        'grupo_modelos',
        'rotulo_modelo',
        'video_path',
        'video_duration_seconds',
        'price',
        'cost_price',
        'discount_percentage',
        'discount_amount',
        'checkout_discount_percentage',
        'checkout_discount_amount',
        'stock',
        'is_active',
        'so_ferrakora',
        'fora_da_vitrine_kz',
        'vitrine_kz_redireciona_para',
        'is_featured',
        'is_new_release',
        'seo_title',
        'seo_description',
        'cta_compra',
        'tags',
        'termos_busca',
        'brinde_fisico',
    ];

    protected $appends = ['video_url', 'final_price', 'has_discount', 'oferta_do_dia', 'oferta_relampago', 'preco_sem_oferta', 'preco_de', 'preco_vitrine', 'preco_cupom', 'layout_card'];

    /** Só serve para calcular o preco_vitrine; não vai no JSON. */
    protected $hidden = ['variacoesAtivas', 'avaliacoes_de', 'pagina_brinquedo', 'escada_kits', 'cartoes_opcoes'];

    protected function casts(): array
    {
        return [
            'parent_product_id' => 'integer',
            'brinde_fisico' => 'array',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'discount_percentage' => 'decimal:4',
            'checkout_discount_percentage' => 'decimal:2',
            'checkout_discount_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
            'so_ferrakora' => 'boolean',
            'fora_da_vitrine_kz' => 'boolean',
            'is_featured' => 'boolean',
            'is_new_release' => 'boolean',
            'video_duration_seconds' => 'integer',
            'tags' => 'array',
            'avaliacoes_de' => 'array',
            'pagina_brinquedo' => 'array',
            'escada_kits' => 'array',
            'cartoes_opcoes' => 'array',
        ];
    }

    /**
     * Renomear troca o slug (ProductController::update): o slug velho fica em
     * product_slugs_antigos para o link antigo responder 301 (2026-10-20).
     */
    protected static function booted(): void
    {
        static::updated(function (Product $product) {
            if (! $product->wasChanged('slug')) {
                return;
            }

            $antigo = $product->getOriginal('slug');
            if ($antigo) {
                ProductSlugAntigo::query()->updateOrCreate(['slug' => $antigo], ['product_id' => $product->id]);
            }
            // O slug novo é do produto agora: não pode redirecionar para outro.
            ProductSlugAntigo::query()->where('slug', $product->slug)->delete();
        });

        // Título do FerraKora/Beauty Kora sai do nome: renomear guarda o
        // endereço antigo do site para ele não dar 404 (2026-11-18).
        static::updated(function (Product $product) {
            if ($product->wasChanged(['name', 'variation'])) {
                foreach ([\App\Modules\FerraKora\Services\CatalogoFerraKora::class, \App\Modules\BeautyKora\Services\CatalogoBeautyKora::class] as $catalogo) {
                    app($catalogo)->aoRenomearProduto($product);
                }
            }
        });

        // Variação vinculada/desvinculada muda de quem ela herda os quadros
        // promocionais (sorteio e livros) guardados em cache.
        static::updated(function (Product $product) {
            if ($product->wasChanged('parent_product_id')) {
                PromocoesDoProduto::limparCache();
            }
        });
    }

    public function getVideoUrlAttribute(): ?string
    {
        return $this->video_path ? asset('storage/'.$this->video_path) : null;
    }

    public function getHasDiscountAttribute(): bool
    {
        return (bool) ($this->discount_percentage || $this->discount_amount) || $this->emOferta();
    }

    /**
     * Preço da loja. Com oferta do dia (pedido 2026-10-10) vale o preço da
     * oferta, já conferido sem prejuízo na escolha do dia. Se o preço do
     * produto baixou depois disso, fica o menor. Com Oferta Relâmpago do
     * site da requisição (pedido 2026-10-14) vale o menor dos dois.
     */
    public function getFinalPriceAttribute(): float
    {
        $base = $this->precoSemOferta();
        $oferta = $this->precoPromocional();

        return round(($oferta !== null ? min($base, $oferta) : $base) + $this->freteEmbutido(), 2);
    }

    /**
     * Frete somado ao preço nas telas da loja (frete grátis com o frete
     * embutido, pedido 2026-10-12). Fora da loja é 0: admin, marketplaces e
     * filas veem o preço de cadastro.
     */
    public function freteEmbutido(): float
    {
        return $this->id ? app(\App\Modules\Checkout\Support\FreteEmbutido::class)->acrescimo($this) : 0.0;
    }

    /**
     * Preço de 1 unidade pagando o pedido todo no Pix (DescontoPix, mesma
     * conta do checkout). Null sem desconto no Pix. Não está no $appends:
     * só a página de produto pede, com append('preco_pix').
     */
    public function getPrecoPixAttribute(): ?float
    {
        return \App\Support\DescontoPix::precoPix($this);
    }

    /**
     * Black Friday automática (2026-11-24): 1 unidade com o cupom, a conta do
     * checkout pronta para o card e a página ({de, por, pix, code, ...}).
     * Null fora da BF e fora da loja KazaKora.
     */
    public function getPrecoCupomAttribute(): ?array
    {
        return $this->id ? \App\Modules\Checkout\Support\BlackFriday::precos($this, 1, false) : null;
    }

    /**
     * Página do produto (com append): o preço com o cupom para cada quantidade
     * até o maior kit, com o desconto do kit e o da caixa única do checkout.
     */
    public function getPrecosCupomAttribute(): ?array
    {
        return $this->id ? \App\Modules\Checkout\Support\BlackFriday::porQuantidade($this) : null;
    }

    /**
     * Layout da página do produto, para o card mostrar o preço do mesmo jeito (27/11/2026):
     * na página de brinquedo o preço grande é o sem cupom ("de 239,00 por 97,99") e o card
     * mostrava o com cupom (93,09). Só "brinquedo" ou null; fora da categoria de brinquedos
     * não consulta nada a mais.
     */
    public function getLayoutCardAttribute(): ?string
    {
        if (! $this->id) {
            return null;
        }
        // Sem layout escolhido e fora da categoria de brinquedos: padrão, sem consultar o conteúdo.
        // Ids das categorias de brinquedo guardados na memória da requisição (store "array"): nada de
        // uma consulta por produto nas listas grandes.
        $brinquedo = \Illuminate\Support\Facades\Cache::store('array')->rememberForever('catalogo:categorias-brinquedo', fn () => Category::whereIn('slug', \App\Modules\Catalog\Support\LayoutDaPagina::CATEGORIAS_BRINQUEDO)->pluck('id')->all());
        if (! $this->layout_pagina && ! in_array($this->category_id, $brinquedo)
            && ($this->parent_product_id === null || (! $this->parent?->layout_pagina && ! in_array($this->parent?->category_id, $brinquedo)))) {
            return null;
        }

        // O card (forCard) não carrega o pagina_brinquedo, que é grande: busca o produto inteiro só
        // aqui, para os poucos da categoria de brinquedos.
        $produto = array_key_exists('pagina_brinquedo', $this->attributes) ? $this : (static::query()->find($this->id) ?? $this);

        return \App\Modules\Catalog\Support\LayoutDaPagina::para($produto) === \App\Modules\Catalog\Support\LayoutDaPagina::BRINQUEDO ? 'brinquedo' : null;
    }

    /** Preço "de" riscado no card quando há desconto, com o mesmo frete embutido do final_price. */
    public function getPrecoDeAttribute(): ?float
    {
        return $this->has_discount && ($this->discount_percentage || $this->discount_amount)
            ? round((float) $this->price + $this->freteEmbutido(), 2)
            : null;
    }

    /** Preço da oferta do dia deste produto (null = não está em oferta hoje). */
    public function precoOfertaDeHoje(): ?float
    {
        return $this->id ? (OfertaDoDia::precosDeHoje()[$this->id] ?? null) : null;
    }

    public function getOfertaDoDiaAttribute(): bool
    {
        return $this->precoOfertaDeHoje() !== null;
    }

    /**
     * Oferta Relâmpago do pai no site da requisição, no ar agora (pedido
     * 2026-10-14). Sem site (admin, marketplaces, filas), nenhuma.
     */
    public function relampagoNoAr(): ?OfertaRelampago
    {
        return $this->id ? (OfertaRelampago::ativasAgora()[$this->parent_product_id ?? $this->id] ?? null) : null;
    }

    /** Preço do anúncio (sem frete) com a Oferta Relâmpago; null fora dela. */
    public function precoRelampago(): ?float
    {
        return $this->relampagoNoAr()?->precoPara($this);
    }

    /** {id, inicio, fim, desconto_pct} da Oferta Relâmpago no ar (card da loja); null fora dela. */
    public function getOfertaRelampagoAttribute(): ?array
    {
        return $this->relampagoNoAr()?->paraApi();
    }

    /** Menor preço entre Oferta do Dia e Oferta Relâmpago (sem frete); null sem nenhuma. */
    public function precoPromocional(): ?float
    {
        $precos = array_filter([$this->precoOfertaDeHoje(), $this->precoRelampago()], fn ($preco) => $preco !== null);

        return $precos === [] ? null : min($precos);
    }

    /** Em Oferta do Dia ou Relâmpago: não acumula com desconto por quantidade nem com cupom. */
    public function emOferta(): bool
    {
        return $this->precoPromocional() !== null;
    }

    /** Preço normal (riscado no card) quando está em oferta (do dia ou relâmpago); null fora da oferta. */
    public function getPrecoSemOfertaAttribute(): ?float
    {
        return $this->emOferta() ? round($this->precoSemOferta() + $this->freteEmbutido(), 2) : null;
    }

    /** Preço sem a oferta do dia — o que vale para os marketplaces. */
    public function precoSemOferta(): float
    {
        if ($this->discount_percentage) {
            return max(0, round((float) $this->price * (1 - (float) $this->discount_percentage / 100), 2));
        }

        if ($this->discount_amount) {
            return max(0, round((float) $this->price - (float) $this->discount_amount, 2));
        }

        return (float) $this->price;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Pedido explícito 2026-08-17 (variações de produto, estilo Shopee/
     * Mercado Livre): auto-referência em vez de tabela separada — cada
     * variação continua sendo um Product completo (SKU/estoque/fotos/
     * dados fiscais/canais próprios, tudo já existente), só ganha um
     * vínculo pro "pai". parent_product_id null = produto standalone OU
     * pai de variações (indistinguível de propósito — "pai sem filhos
     * ainda" é o mesmo estado que "nunca teve variação").
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    /**
     * Nome que vai no item do pedido (e daí na NF-e e na separação) quando
     * o canal não manda título próprio: o nome interno, se houver, senão o
     * name. O name é título de vitrine desde 18/11/2026 ("... + PDF Grátis").
     */
    public function nomeParaPedido(): string
    {
        return filled($this->nome_interno) ? trim((string) $this->nome_interno) : (string) $this->name;
    }

    public function children(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    /** Variações ativas, só com o que entra no preço do card (scopeComPrecoDaVariacao). */
    public function variacoesAtivas(): HasMany
    {
        return $this->children()->where('is_active', true)
            ->select(['id', 'parent_product_id', 'price', 'discount_percentage', 'discount_amount', 'checkout_discount_percentage', 'checkout_discount_amount', 'stock', 'categoria_frete']);
    }

    /** Carrega as variações ativas do pai para o card mostrar o menor preço. */
    public function scopeComPrecoDaVariacao(Builder $query): Builder
    {
        return $query->with('variacoesAtivas');
    }

    /**
     * Anúncio com variação mostra o menor preço (pedido 2026-10-16, ex.:
     * Árvore de Natal): entre o pai e as variações ativas, a mais barata,
     * contando só as com estoque quando houver alguma. Sem as variações
     * carregadas (comPrecoDaVariacao), é o próprio produto.
     */
    public function variacaoMaisBarata(): Product
    {
        if ($this->parent_product_id !== null || ! $this->relationLoaded('variacoesAtivas') || $this->variacoesAtivas->isEmpty()) {
            return $this;
        }

        $opcoes = collect([$this])->concat($this->variacoesAtivas);
        $comEstoque = $opcoes->filter(fn (Product $opcao) => $opcao->stock > 0);

        return ($comEstoque->isNotEmpty() ? $comEstoque : $opcoes)->sortBy(fn (Product $opcao) => $opcao->final_price)->first();
    }

    /**
     * Preço do card (ProductCard) de anúncio com variação: os campos de preço
     * da variação mais barata e `a_partir_de` quando as variações têm preços
     * diferentes. Null em produto sem variação ou sem comPrecoDaVariacao.
     */
    public function getPrecoVitrineAttribute(): ?array
    {
        if ($this->parent_product_id !== null || ! $this->relationLoaded('variacoesAtivas') || $this->variacoesAtivas->isEmpty()) {
            return null;
        }

        $maisBarata = $this->variacaoMaisBarata();
        $precos = collect([$this])->concat($this->variacoesAtivas)->map(fn (Product $opcao) => $opcao->final_price);

        return [
            // Variação do preço mostrado: o card abre a página nela (?opcao=), para o cliente
            // ver o mesmo preço do card (27/11/2026: árvore R$ 369 no card abria em R$ 622).
            'id' => $maisBarata->id,
            'final_price' => $maisBarata->final_price,
            'price' => (float) $maisBarata->price,
            'has_discount' => $maisBarata->has_discount,
            'preco_de' => $maisBarata->preco_de,
            'preco_sem_oferta' => $maisBarata->preco_sem_oferta,
            'oferta_do_dia' => $maisBarata->oferta_do_dia,
            'oferta_relampago' => $maisBarata->oferta_relampago,
            'preco_cupom' => $maisBarata->preco_cupom,
            'a_partir_de' => $precos->min() < $precos->max(),
        ];
    }

    /**
     * IDs de todo o grupo de variações (o produto em si + irmãos), sempre
     * incluindo o próprio ID mesmo sem variação nenhuma — pensado pra
     * substituir direto todo `where('product_id', $product->id)` que
     * decide "é o mesmo produto" (avaliação, favorito): uma compra da
     * variação "10 Polegadas" precisa contar como elegível pra avaliar a
     * variação "8 Polegadas" do mesmo item físico.
     *
     * @return array<int, int>
     */
    public function variantGroupIds(): array
    {
        $parentId = $this->parent_product_id ?? $this->id;

        if ($parentId === $this->id) {
            return [$this->id, ...$this->children()->pluck('id')->all()];
        }

        return [
            $parentId,
            ...static::query()->where('parent_product_id', $parentId)->pluck('id')->all(),
        ];
    }

    /**
     * Performance 2026-09-03: a vitrine (ProductCard) usa um punhado de
     * campos, mas a home mandava o produto INTEIRO — inclusive a
     * `description`, que é o texto completo do anúncio (alguns passam de
     * 4 KB cada). Com 17 cards na home isso sozinho respondia por ~75%
     * do payload Inertia da página.
     *
     * Este escopo é o contrato de "produto para card": qualquer tela que
     * renderiza ProductCard (home, favoritos, relacionados) usa ele. Ao
     * mexer no ProductCard, confira se o campo novo está aqui.
     *
     * `parent_product_id` entra porque variantGroupIds() depende dele
     * (relatedReviewableIds em CatalogController::show) — sem ele uma
     * variação viraria silenciosamente um produto solto.
     */
    /**
     * Produto que aparece na loja kazakora.com: ativo e não marcado como só
     * do FerraKora (pedido 2026-10-29).
     */
    public function scopeNaKazaKora(Builder $query): Builder
    {
        // fora_da_vitrine_kz (26/11/2026): ativo para marketplace e pedidos, fora da loja.
        return $query->where('is_active', true)->where('so_ferrakora', false)->where('fora_da_vitrine_kz', false);
    }

    public function naKazaKora(): bool
    {
        return $this->is_active && ! $this->so_ferrakora && ! $this->fora_da_vitrine_kz;
    }

    /**
     * Para onde o link deste produto na kazakora.com responde 301 (26/11/2026): [página, id da
     * opção já escolhida ou null]. Dois casos: produto fora da vitrine (fora_da_vitrine_kz) vai
     * para vitrine_kz_redireciona_para; variação com vitrine_kz_redireciona_para = o próprio pai
     * vai para a página do pai com ela escolhida (anúncio único com opções, Compressor 4 em 1).
     * null = a página deste produto responde normalmente.
     *
     * @return array{0: self, 1: int|null}|null
     */
    public function redirecionamentoNaKazaKora(): ?array
    {
        if ($this->opcaoDoPai()) {
            return [$this->parent, $this->id];
        }
        if (! $this->fora_da_vitrine_kz || ! ($destino = $this->paginaNaKazaKora()) || $destino->id === $this->id) {
            return null;
        }

        return $destino->opcaoDoPai() ? [$destino->parent, $destino->id] : [$destino, null];
    }

    /** Variação que abre como opção na página do pai (vitrine_kz_redireciona_para = pai). */
    private function opcaoDoPai(): bool
    {
        return $this->parent_product_id
            && (int) $this->vitrine_kz_redireciona_para === (int) $this->parent_product_id
            && $this->naKazaKora()
            && $this->parent?->naKazaKora()
            && $this->parent->slug;
    }

    /** Endereço do redirecionamento com a query de quem chegou (UTMs, fbclid) e a opção. */
    public static function enderecoDoRedirecionamento(array $redirecionamento, ?string $query): string
    {
        [$pagina, $opcao] = $redirecionamento;
        parse_str((string) $query, $parametros);
        unset($parametros['opcao']);
        if ($opcao) {
            $parametros['opcao'] = $opcao;
        }
        $query = http_build_query($parametros);

        return route('produtos.ver', $pagina->slug).($query !== '' ? '?'.$query : '');
    }

    /**
     * Página da kazakora.com que responde por este produto: ele mesmo, se está na vitrine, ou o
     * produto para onde ele foi redirecionado (fora_da_vitrine_kz, 26/11/2026). null = 404.
     */
    public function paginaNaKazaKora(): ?self
    {
        if ($this->naKazaKora()) {
            return $this;
        }
        if (! $this->fora_da_vitrine_kz || ! $this->vitrine_kz_redireciona_para) {
            return null;
        }
        $destino = self::query()->find($this->vitrine_kz_redireciona_para);

        return $destino && $destino->naKazaKora() && $destino->slug ? $destino : null;
    }

    /**
     * Texto do botão principal de compra da página (pedido 2026-11-24): o do
     * produto, senão o do produto pai (variação sem texto próprio). Nulo =
     * a página usa "Comprar agora".
     */
    public function textoBotaoCompra(): ?string
    {
        if (filled($this->cta_compra)) {
            return $this->cta_compra;
        }

        return $this->parent_product_id ? $this->parent()->value('cta_compra') ?: null : null;
    }

    /**
     * Produto esgotado não aparece na loja (pedido 2026-10-13): vitrine,
     * busca, departamentos e relacionados. Pai de variações conta como
     * disponível se alguma variação ativa tiver estoque. A página do produto
     * continua abrindo pelo link direto (mostra "esgotado").
     */
    public function scopeComEstoque(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('products.stock', '>', 0)
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('products as variacoes_estoque')
                ->whereColumn('variacoes_estoque.parent_product_id', 'products.id')
                ->where('variacoes_estoque.is_active', true)
                ->whereNull('variacoes_estoque.deleted_at')
                ->where('variacoes_estoque.stock', '>', 0)));
    }

    public function scopeForCard(Builder $query): Builder
    {
        return $query
            ->select([
                'id',
                'parent_product_id',
                'category_id',
                'name',
                'slug',
                'brand',
                'model',
                'color',
                'price',
                'discount_percentage',
                'discount_amount',
                'checkout_discount_percentage',
                'checkout_discount_amount',
                'stock',
                // Sem ela o frete embutido do card ia pelos Correios e o da
                // página pela tabela por localidade: preços diferentes (2026-10-25).
                'categoria_frete',
                'created_at',
                // Kit que junta avaliações de outros produtos (26/11/2026): ver avaliacoesDeOutrosNoCard().
                'avaliacoes_de',
                // Card de brinquedo mostra o preço como a página (27/11/2026): ver getLayoutCardAttribute().
                'layout_pagina',
            ])
            // Anúncio com variação mostra o menor preço (pedido 2026-10-16).
            ->comPrecoDaVariacao()
            // Card usa só a foto principal e a do hover: as outras iam no JSON
            // de toda vitrine à toa (velocidade, pedido 2026-10-10).
            ->with(['images' => fn ($images) => $images->select(['id', 'product_id', 'path', 'thumb_path', 'position', 'is_primary'])
                ->reorder()->orderByDesc('is_primary')->orderBy('position')->limit(2)])
            // Média do produto E das variações (pedido 2026-10-20): a avaliação
            // importada fica na variação vendida, e o withAvg só via a do pai,
            // então anúncio com variação ficava sem estrela no card. Mesma
            // regra da nota da página do produto (CatalogController::show).
            // Quantidade de avaliações ao lado das estrelas (pedido 2026-10-22),
            // pela mesma regra, para bater com o número da página do produto.
            ->addSelect([
                'reviews_avg_rating' => self::avaliacoesDoGrupo('avg(reviews.rating)'),
                'reviews_count' => self::avaliacoesDoGrupo('count(*)'),
            ])
            ->afterQuery(fn ($produtos) => self::avaliacoesDeOutrosNoCard($produtos));
    }

    /**
     * Card de kit com avaliacoes_de (pedido do Lira 26/11/2026, "produto sem avaliação no card"):
     * a página do kit já junta as avaliações reais dos produtos que vêm nele
     * (CatalogController::avaliacoesDeOutros), mas o card contava só o grupo do próprio kit e
     * mostrava "(0)". Aqui a nota e a quantidade do card passam a usar o mesmo conjunto da
     * página: o grupo do kit mais cada produto listado e as variações dele, sem repetir.
     * Só roda para os poucos cards que têm avaliacoes_de; nada é copiado nem inventado.
     */
    private static function avaliacoesDeOutrosNoCard($produtos)
    {
        foreach ($produtos as $produto) {
            if (! $produto instanceof self || ! array_key_exists('avaliacoes_de', $produto->getAttributes())) {
                continue;
            }
            $lista = $produto->avaliacoes_de;
            $outros = is_array($lista) ? array_values(array_filter(array_map(fn ($item) => (int) ($item['id'] ?? 0), $lista))) : [];
            if (! $outros) {
                continue;
            }
            // Mesmo conjunto de CatalogController::idsDasAvaliacoes(): o grupo do kit e, com as
            // apagadas, cada produto listado e as variações dele.
            $ids = self::query()->where('id', $produto->id)->orWhere('parent_product_id', $produto->id)->pluck('id')
                ->merge(self::withTrashed()->whereIn('id', $outros)->orWhereIn('parent_product_id', $outros)->pluck('id'))
                ->unique()->values();
            $resumo = Review::query()->whereIn('product_id', $ids)->toBase()
                ->selectRaw('count(*) as total, avg(rating) as media')->first();
            $produto->setAttribute('reviews_count', (int) ($resumo->total ?? 0));
            $produto->setAttribute('reviews_avg_rating', ($resumo->total ?? 0) ? (float) $resumo->media : null);
        }

        return $produtos;
    }

    /**
     * Avaliações do produto e das variações dele (subconsulta do card).
     *
     * Lentidão da página de produto (tarefa 3878, 06/11): partindo de reviews,
     * o MariaDB lia as 9 mil avaliações para cada card (347 ms nos 95 produtos
     * da loja). Partindo do grupo de produtos e só depois juntando as
     * avaliações pelo índice (STRAIGHT_JOIN fixa essa ordem) dá o mesmo
     * resultado em 19 ms. O SQLite dos testes não conhece STRAIGHT_JOIN.
     */
    private static function avaliacoesDoGrupo(string $agregado): QueryBuilder
    {
        $ordemFixa = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'straight_join ' : '';

        return DB::table('products as grupo_avaliado')
            ->join('reviews', 'reviews.product_id', '=', 'grupo_avaliado.id')
            ->whereNull('grupo_avaliado.deleted_at')
            ->where(fn ($grupo) => $grupo->whereColumn('grupo_avaliado.id', 'products.id')
                ->orWhereColumn('grupo_avaliado.parent_product_id', 'products.id'))
            ->selectRaw($ordemFixa.$agregado);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function quantityDiscounts(): HasMany
    {
        return $this->hasMany(ProductQuantityDiscount::class)->orderBy('min_quantity');
    }

    public function unitPriceForQuantity(int $quantity): float
    {
        $tier = $this->quantityDiscounts
            ->filter(fn (ProductQuantityDiscount $discount) => $discount->min_quantity <= $quantity)
            ->last();

        // Oferta do dia e Oferta Relâmpago não acumulam com desconto por
        // quantidade (a conta de lucro da oferta foi feita sem ele).
        if (! $tier || $this->emOferta()) {
            return $this->final_price;
        }

        return max(0, round($this->final_price * (1 - (float) $tier->discount_percentage / 100), 2));
    }

    /** Conteúdo do anúncio (benefícios + descrição em blocos) — pedido 2026-10-09. */
    public function adContent(): HasOne
    {
        return $this->hasOne(ProductAdContent::class);
    }

    public function fiscalData(): HasOne
    {
        return $this->hasOne(ProductFiscalData::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function channelListings(): HasMany
    {
        return $this->hasMany(ProductChannelListing::class);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}

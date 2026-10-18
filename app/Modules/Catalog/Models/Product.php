<?php

namespace App\Modules\Catalog\Models;


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

class Product extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'parent_product_id',
        'sku',
        'sku_anterior',
        'name',
        'slug',
        'description',
        'brand',
        'model',
        'color',
        'variation',
        'video_path',
        'video_duration_seconds',
        'price',
        'cost_price',
        'min_profit',
        'discount_percentage',
        'discount_amount',
        'checkout_discount_percentage',
        'checkout_discount_amount',
        'stock',
        'is_active',
        'is_featured',
        'is_new_release',
        'is_daily_offer',
    ];

    protected $appends = ['video_url', 'final_price', 'has_discount', 'oferta_do_dia', 'oferta_relampago', 'preco_sem_oferta', 'preco_de', 'preco_vitrine'];

    /** Só serve para calcular o preco_vitrine; não vai no JSON. */
    protected $hidden = ['variacoesAtivas'];

    protected function casts(): array
    {
        return [
            'parent_product_id' => 'integer',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'discount_percentage' => 'decimal:4',
            'checkout_discount_percentage' => 'decimal:2',
            'checkout_discount_amount' => 'decimal:2',
            'min_profit' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new_release' => 'boolean',
            'is_daily_offer' => 'boolean',
            'video_duration_seconds' => 'integer',
        ];
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

    public function children(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    /** Variações ativas, só com o que entra no preço do card (scopeComPrecoDaVariacao). */
    public function variacoesAtivas(): HasMany
    {
        return $this->children()->where('is_active', true)
            ->select(['id', 'parent_product_id', 'price', 'discount_percentage', 'discount_amount', 'checkout_discount_percentage', 'checkout_discount_amount', 'stock']);
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
            'final_price' => $maisBarata->final_price,
            'price' => (float) $maisBarata->price,
            'has_discount' => $maisBarata->has_discount,
            'preco_de' => $maisBarata->preco_de,
            'preco_sem_oferta' => $maisBarata->preco_sem_oferta,
            'oferta_do_dia' => $maisBarata->oferta_do_dia,
            'oferta_relampago' => $maisBarata->oferta_relampago,
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
                'created_at',
            ])
            // Anúncio com variação mostra o menor preço (pedido 2026-10-16).
            ->comPrecoDaVariacao()
            // Card usa só a foto principal e a do hover: as outras iam no JSON
            // de toda vitrine à toa (velocidade, pedido 2026-10-10).
            // Vendidos (pedido 2026-10-10, como no Mercado Livre): pedidos pagos,
            // enviados ou entregues de todos os canais, somando as variações.
            ->addSelect(['vendidos' => \App\Modules\Checkout\Models\OrderItem::query()
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('orders.status', ['paid', 'shipped', 'completed'])
                ->whereIn('order_items.product_id', fn ($sub) => $sub->select('variacoes.id')->from('products as variacoes')
                    ->whereColumn('variacoes.id', 'products.id')
                    ->orWhereColumn('variacoes.parent_product_id', 'products.id'))])
            ->with(['images' => fn ($images) => $images->select(['id', 'product_id', 'path', 'thumb_path', 'position', 'is_primary'])
                ->reorder()->orderByDesc('is_primary')->orderBy('position')->limit(2)])
            ->withAvg('reviews', 'rating');
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

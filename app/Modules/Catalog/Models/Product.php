<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Support\Rbac\Auditable;
use Database\Factories\ProductFactory;
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
        'discount_percentage',
        'discount_amount',
        'stock',
        'is_active',
        'is_featured',
        'is_new_release',
    ];

    protected $appends = ['video_url', 'final_price', 'has_discount'];

    protected function casts(): array
    {
        return [
            'parent_product_id' => 'integer',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new_release' => 'boolean',
            'video_duration_seconds' => 'integer',
        ];
    }

    public function getVideoUrlAttribute(): ?string
    {
        return $this->video_path ? asset('storage/'.$this->video_path) : null;
    }

    public function getHasDiscountAttribute(): bool
    {
        return (bool) ($this->discount_percentage || $this->discount_amount);
    }

    public function getFinalPriceAttribute(): float
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

        if (! $tier) {
            return $this->final_price;
        }

        return max(0, round($this->final_price * (1 - (float) $tier->discount_percentage / 100), 2));
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

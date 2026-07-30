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
        'stock',
        'is_active',
        'is_featured',
        'is_new_release',
    ];

    protected $appends = ['video_url'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
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

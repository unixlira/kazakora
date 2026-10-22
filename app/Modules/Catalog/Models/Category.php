<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\BannerMobileOptimizer;
use App\Support\Rbac\Auditable;
use App\Support\TituloPtBr;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image_path',
    ];

    protected $appends = ['image_url'];

    /** Nome sempre em formato de título pt-BR (pedido 2026-10-10). */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => TituloPtBr::formatar($value));
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * WebP de até 480 px ao lado do original, quando já foi gerado
     * (categorias:gerar-webp, PageSpeed 2026-10-21); senão o original.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }
        if (! str_ends_with(strtolower($this->image_path), '.webp')) {
            $webp = BannerMobileOptimizer::webpAoLado($this->image_path);
            if (Storage::disk('public')->exists($webp)) {
                return asset('storage/'.$webp);
            }
        }

        return asset('storage/'.$this->image_path);
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}

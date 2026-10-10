<?php

namespace App\Modules\Catalog\Models;

use App\Support\Rbac\Auditable;
use App\Support\TituloPtBr;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}

<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slug que o produto já teve (Lira, 2026-10-20): link velho responde 301
 * para o slug atual em vez de 404. Gravado sozinho pelo Product ao trocar o
 * slug (renomear no admin).
 */
class ProductSlugAntigo extends Model
{
    protected $table = 'product_slugs_antigos';

    protected $fillable = ['product_id', 'slug'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

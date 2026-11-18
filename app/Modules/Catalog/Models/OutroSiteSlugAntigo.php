<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slug que um produto já teve num site irmão (FerraKora, Beauty Kora), para
 * o link antigo achar o produto (2026-11-18). Gravado por
 * CatalogoOutroSite::registrarTroca(); ver a migration de 2026-11-18.
 */
class OutroSiteSlugAntigo extends Model
{
    protected $table = 'outro_site_slugs_antigos';

    protected $fillable = ['site', 'product_id', 'slug'];

    protected function casts(): array
    {
        return ['product_id' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

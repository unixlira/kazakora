<?php

namespace App\Modules\Editorial\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;

class EditorialPost extends Model
{
    /** Sites com blog (23/11/2026): cada matéria aparece só no seu. */
    public const KAZAKORA = 'kazakora';

    public const FERRAKORA = 'ferrakora';

    public const SITES = [self::KAZAKORA, self::FERRAKORA];

    protected $guarded = [];

    protected $attributes = ['site' => self::KAZAKORA];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'rights_confirmed' => 'boolean', 'editorial_checked' => 'boolean'];
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'editorial_post_product');
    }

    public function scopeDoSite($q, string $site)
    {
        return $q->where('site', $site);
    }

    public function scopeVisible($q)
    {
        return $q->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}

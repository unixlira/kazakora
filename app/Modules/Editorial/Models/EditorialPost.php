<?php

namespace App\Modules\Editorial\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;

class EditorialPost extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'rights_confirmed' => 'boolean', 'editorial_checked' => 'boolean'];
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'editorial_post_product');
    }

    public function scopeVisible($q)
    {
        return $q->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}

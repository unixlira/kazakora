<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'position',
        'is_primary',
    ];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): string
    {
        // Defensive: normalize away an accidental leading "storage/" so a
        // bad path value can't silently produce a broken storage/storage/...
        // URL — the real fix is keeping $path storage-relative in the first
        // place, this is just a safety net.
        return asset('storage/'.ltrim(preg_replace('#^storage/#', '', $this->path), '/'));
    }
}

<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuantityDiscount extends Model
{
    protected $fillable = [
        'product_id',
        'min_quantity',
        'discount_percentage',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'discount_percentage' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

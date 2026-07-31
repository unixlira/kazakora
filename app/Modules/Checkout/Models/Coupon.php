<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function discountFor(float $subtotal): float
    {
        if ($this->discount_type === self::TYPE_PERCENTAGE) {
            return round($subtotal * ((float) $this->discount_value / 100), 2);
        }

        return min($subtotal, round((float) $this->discount_value, 2));
    }
}

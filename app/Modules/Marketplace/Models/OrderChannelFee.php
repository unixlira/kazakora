<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderChannelFee extends Model
{
    public const SOURCE_API = 'api';

    protected $fillable = [
        'order_id',
        'channel',
        'gross_amount',
        'fee_amount',
        'source',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function netAmount(): float
    {
        return round((float) $this->gross_amount - (float) $this->fee_amount, 2);
    }
}

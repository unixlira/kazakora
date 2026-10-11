<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceClaim extends Model
{
    protected $fillable = [
        'order_id',
        'channel',
        'external_claim_id',
        'type',
        'stage',
        'status',
        'reason_id',
        'resolution',
        'raw_payload',
        'claim_created_at',
        'claim_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'resolution' => 'array',
            'raw_payload' => 'array',
            'claim_created_at' => 'datetime',
            'claim_updated_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

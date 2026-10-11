<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lançamento manual de recarga de saldo de anúncio (Shopee Ads / Mercado
 * Ads) — nenhuma das duas APIs expõe histórico de recarga consultável, ver
 * migration.
 */
class AdsRecharge extends Model
{
    protected $fillable = [
        'channel',
        'amount',
        'recharge_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recharge_date' => 'date:Y-m-d',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

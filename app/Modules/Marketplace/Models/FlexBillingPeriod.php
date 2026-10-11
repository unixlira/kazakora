<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class FlexBillingPeriod extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'deliveries_count',
        'cost_per_delivery',
        'total_amount',
        'email_sent_at',
        'email_error',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'deliveries_count' => 'integer',
            'cost_per_delivery' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'email_sent_at' => 'datetime',
        ];
    }

    protected function wasEmailed(): Attribute
    {
        return Attribute::get(fn () => $this->email_sent_at !== null);
    }
}

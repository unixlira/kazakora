<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceGenerationLog extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'invoice_id',
        'attempt',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->created_at ??= now();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

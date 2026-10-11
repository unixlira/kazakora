<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEmailLog extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    // Achado real 2026-08-08: pedido de canal sem e-mail nenhum do
    // comprador (a Shopee nunca manda esse dado, por exemplo) não é uma
    // falha técnica pra tentar de novo — não existe pra quem mandar, ponto.
    // Ver SendOrderReceiptEmailJob::handle().
    public const STATUS_SKIPPED = 'skipped';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'mailable',
        'attempt',
        'status',
        'invoice_attached',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'invoice_attached' => 'boolean',
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
}

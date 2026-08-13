<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFulfillmentEvent extends Model
{
    public const STEP_WEBHOOK_RECEIVED = 'webhook_received';

    public const STEP_STOCK_UPDATED = 'stock_updated';

    public const STEP_INVOICE_ISSUED = 'invoice_issued';

    public const STEP_INVOICE_SUBMITTED = 'invoice_submitted';

    public const STEP_SHIPPING_CONFIRMED = 'shipping_confirmed';

    public const STEP_LABEL_GENERATED = 'label_generated';

    public const STEP_LABEL_PRINTED = 'label_printed';

    // Pedido explícito 2026-08-13: botão "Em preparação" -> "Embalado" no
    // KoraSync (ver Order::packed_at / DashboardAgentController::packOrder).
    public const STEP_ORDER_PACKED = 'order_packed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id',
        'step',
        'status',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

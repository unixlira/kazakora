<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelInvoiceSubmission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'order_id',
        'invoice_id',
        'channel',
        'status',
        'external_reference',
        'response_payload',
        'error_message',
        'submitted_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'submitted_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
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

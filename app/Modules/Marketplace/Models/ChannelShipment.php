<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelShipment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_LABEL_READY = 'label_ready';

    public const STATUS_LABEL_DOWNLOADED = 'label_downloaded';

    public const STATUS_ERROR = 'error';

    public const METHOD_FLEX = 'self_service';

    public const METHOD_DROP_OFF = 'drop_off';

    public const METHOD_FULFILLMENT = 'fulfillment';

    protected $fillable = [
        'order_id',
        'channel',
        'external_shipment_id',
        'tracking_code',
        'shipping_method',
        'status',
        'label_path',
        'raw_label_path',
        'error_message',
        'confirmed_at',
        'label_ready_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'label_ready_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

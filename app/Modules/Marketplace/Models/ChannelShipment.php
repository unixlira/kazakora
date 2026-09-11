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

    /** Devolução conferida por uma pessoa: o produto está de volta na loja. */
    public const RETURN_BACK_IN_STORE = 'voltou';

    /** Devolução encerrada sem o produto voltar (reembolso do canal, perda assumida...). */
    public const RETURN_CLOSED_WITHOUT_ITEM = 'sem_retorno';

    /** Conferido por uma pessoa e sem problema (ex.: o ML só atrasou a baixa da rota). */
    public const RETURN_CHECKED = 'conferido';

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
        'unrecoverable_at',
        'confirmed_at',
        'scheduled_for',
        'label_ready_at',
        'channel_status',
        'channel_substatus',
        'channel_shipped_at',
        'channel_first_visit_at',
        'channel_delivered_at',
        'channel_not_delivered_at',
        'channel_returned_at',
        'channel_cancelled_at',
        'channel_status_checked_at',
        'return_resolution',
        'return_resolved_at',
        'return_resolved_by',
        'return_note',
        'flex_alerts',
        'flex_alerts_notified',
        'flex_alerts_resolved',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'unrecoverable_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'label_ready_at' => 'datetime',
            'channel_shipped_at' => 'datetime',
            'channel_first_visit_at' => 'datetime',
            'channel_delivered_at' => 'datetime',
            'channel_not_delivered_at' => 'datetime',
            'channel_returned_at' => 'datetime',
            'channel_cancelled_at' => 'datetime',
            'channel_status_checked_at' => 'datetime',
            'return_resolved_at' => 'datetime',
            'flex_alerts' => 'array',
            'flex_alerts_notified' => 'array',
            'flex_alerts_resolved' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

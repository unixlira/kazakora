<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorreiosPrePostagem extends Model
{
    protected $table = 'correios_pre_postagens';

    public const STATUS_GERADA = 'gerada';

    public const STATUS_ERRO = 'erro';

    protected $fillable = [
        'order_id',
        'created_by',
        'origin',
        'external_order_id',
        'customer_name',
        'customer_document',
        'customer_phone',
        'customer_email',
        'zip',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'service_code',
        'service_label',
        'weight_grams',
        'dimension_format',
        'dimension_height',
        'dimension_width',
        'dimension_length',
        'dimension_diameter',
        'content_items',
        'status',
        'correios_id',
        'codigo_objeto',
        'qr_payload',
        'raw_response',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'content_items' => 'array',
            'raw_response' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

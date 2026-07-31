<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const STATUS_REQUIRES_CONFIRMATION = 'requires_confirmation';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_REFUNDED = 'refunded';

    public const METHOD_CARD = 'card';

    public const METHOD_PIX = 'pix';

    public const METHOD_BOLETO = 'boleto';

    public const PROVIDER_STRIPE = 'stripe';

    public const PROVIDER_MERCADOPAGO = 'mercadopago';

    protected $fillable = [
        'order_id',
        'provider',
        'stripe_payment_intent_id',
        'mercadopago_payment_id',
        'method_type',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

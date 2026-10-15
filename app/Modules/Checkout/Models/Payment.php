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
        'mercadopago_order_id',
        'method_type',
        'amount',
        'status',
        'boleto_linha_digitavel',
        'boleto_url',
        'expira_em',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expira_em' => 'datetime',
        ];
    }

    /** Boleto do Mercado Pago ainda dentro do jogo (gerado e não pago nem cancelado). */
    public function boletoPendente(): bool
    {
        return $this->method_type === self::METHOD_BOLETO
            && $this->provider === self::PROVIDER_MERCADOPAGO
            && $this->status === self::STATUS_REQUIRES_CONFIRMATION;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

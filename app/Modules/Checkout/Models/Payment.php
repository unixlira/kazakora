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
        'site_origem',
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
        'motivo_recusa',
    ];

    /**
     * Pagamento marcado com o site do pedido (FerraKora, Beauty Kora; nulo =
     * kazakora.com), pedido do Lira 2026-10-15 para separar os dados dos sites.
     */
    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->site_origem ??= Order::query()->whereKey($payment->order_id)->value('site_origem');
        });
    }

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

    /**
     * Pagamento recusado pelo Mercado Pago depois de criado (webhook, polling,
     * varredura): cancela e guarda o status_detail para a tela do pedido.
     */
    public function recusar(array $mpPayment): void
    {
        $this->update([
            'status' => self::STATUS_CANCELED,
            'motivo_recusa' => in_array($mpPayment['status'] ?? null, ['failed', 'rejected'], true) && isset($mpPayment['status_detail'])
                ? mb_substr((string) $mpPayment['status_detail'], 0, 80)
                : $this->motivo_recusa,
        ]);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

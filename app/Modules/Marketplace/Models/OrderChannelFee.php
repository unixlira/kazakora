<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderChannelFee extends Model
{
    public const SOURCE_API = 'api';

    // Taxa real vinda de extrato financeiro importado do marketplace
    // (TikTok Shop Income/Settlement, por exemplo). É dado real da plataforma,
    // só chega por planilha/relatório em vez de API.
    public const SOURCE_REPORT = 'report';

    // Comissão digitada à mão na tela de Fluxo de Caixa quando o canal não
    // devolveu a taxa real (ver CashFlowController::updateSaleFee) — pedido
    // explícito 2026-08-14.
    public const SOURCE_MANUAL = 'manual';

    // Taxa ESTIMADA (TikTok Shop, enquanto o extrato real não chega — ver
    // TikTokFeeEstimator). É a única que pode ser sobrescrita por qualquer
    // dado real; a tela marca a margem do canal como estimada.
    public const SOURCE_ESTIMATE = 'estimate';

    protected $fillable = [
        'order_id',
        'channel',
        'gross_amount',
        'fee_amount',
        'commission_fee',
        'service_fee',
        'shipping_fee',
        'seller_discount',
        'platform_discount',
        'payout_amount',
        'breakdown',
        'source',
        'computed_at',
    ];

    /**
     * Componentes da taxa que o driver pode devolver em
     * `marketplace_fee_breakdown` (ver ShopeeDriver/MercadoLivreDriver).
     */
    public const COMPONENTES = ['commission_fee', 'service_fee', 'shipping_fee', 'seller_discount', 'platform_discount', 'payout_amount', 'breakdown'];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'commission_fee' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'seller_discount' => 'decimal:2',
            'platform_discount' => 'decimal:2',
            'payout_amount' => 'decimal:2',
            'breakdown' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function netAmount(): float
    {
        return round((float) $this->gross_amount - (float) $this->fee_amount, 2);
    }
}

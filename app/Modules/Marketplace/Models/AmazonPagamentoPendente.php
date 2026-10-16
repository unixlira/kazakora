<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmazonPagamentoPendente extends Model
{
    protected $table = 'amazon_pagamentos_pendentes';

    public const RESULTADO_PAGO = 'pago';

    public const RESULTADO_CANCELADO_AMAZON = 'cancelado_na_amazon';

    public const RESULTADO_CANCELADO_PRAZO = 'cancelado_por_prazo';

    protected $fillable = [
        'order_id',
        'amazon_order_id',
        'status_amazon',
        'pedido_amazon',
        'pendente_desde',
        'conferido_em',
        'conferencias',
        'resolvido_em',
        'resultado',
    ];

    protected function casts(): array
    {
        return [
            'pedido_amazon' => 'array',
            'pendente_desde' => 'datetime',
            'conferido_em' => 'datetime',
            'resolvido_em' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

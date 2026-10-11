<?php

namespace App\Modules\Financeiro\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um pagamento da conta do Mercado Pago (ver ConciliacaoMercadoPago). */
class MercadoPagoPagamento extends Model
{
    protected $table = 'mercado_pago_pagamentos';

    public const CANAL_LOJA = 'loja';

    public const CANAL_MERCADO_LIVRE = 'mercado_livre';

    // Bônus do Flex que o ML deposita ("bonificaciones_flex").
    public const CANAL_BONIFICACAO = 'bonificacao';

    public const CANAL_OUTRO = 'outro';

    /** Dinheiro que ficou (ou vai ficar) na conta. */
    public const STATUS_VALIDOS = ['approved', 'authorized', 'in_mediation'];

    /** Dinheiro que voltou pro comprador. */
    public const STATUS_ESTORNO = ['refunded', 'charged_back'];

    protected $fillable = [
        'mp_payment_id', 'data_criacao', 'data_aprovacao', 'data_liberacao', 'status', 'status_detalhe',
        'tipo_operacao', 'canal', 'ml_order_id', 'referencia_externa', 'descricao', 'meio_pagamento',
        'valor', 'liquido', 'reembolsado', 'order_id',
    ];

    protected function casts(): array
    {
        return [
            'data_criacao' => 'datetime',
            'data_aprovacao' => 'datetime',
            'data_liberacao' => 'datetime',
            'valor' => 'decimal:2',
            'liquido' => 'decimal:2',
            'reembolsado' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

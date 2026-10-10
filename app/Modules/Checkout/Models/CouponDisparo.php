<?php

namespace App\Modules\Checkout\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Disparo em lote de um cupom (pedido 2026-10-10): pra quem foi (público),
 * por onde (e-mail e/ou notificação no site) e quanto já saiu.
 */
class CouponDisparo extends Model
{
    public const STATUS_PENDENTE = 'pendente';

    public const STATUS_ENVIANDO = 'enviando';

    public const STATUS_CONCLUIDO = 'concluido';

    protected $fillable = [
        'coupon_id', 'publico', 'dias', 'ocasiao', 'assunto', 'mensagem', 'canais',
        'total', 'enviados', 'falhas', 'status', 'created_by', 'concluido_em',
    ];

    protected function casts(): array
    {
        return [
            'canais' => 'array',
            'concluido_em' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

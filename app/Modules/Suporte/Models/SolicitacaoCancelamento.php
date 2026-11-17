<?php

namespace App\Modules\Suporte\Models;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de cancelamento de compra já postada (Lira, 2026-11-17). O botão
 * "Cancelar pedido" de Meus pedidos registra esta linha e um chamado em vez
 * de cancelar; a equipe trata em Atendimento > Cancelamentos.
 */
class SolicitacaoCancelamento extends Model
{
    public const PENDENTE = 'pendente';

    public const RESOLVIDO = 'resolvido';

    protected $table = 'cancelamento_solicitacoes';

    protected $fillable = [
        'order_id', 'user_id', 'chamado_id', 'motivo', 'postagem_fonte', 'postado_em', 'rastreio',
        'status', 'solicitado_em', 'resolvido_em', 'resolvido_por',
    ];

    protected $attributes = [
        'status' => self::PENDENTE,
    ];

    protected function casts(): array
    {
        return [
            'postado_em' => 'datetime',
            'solicitado_em' => 'datetime',
            'resolvido_em' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chamado(): BelongsTo
    {
        return $this->belongsTo(Chamado::class);
    }

    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }

    public function pendente(): bool
    {
        return $this->status === self::PENDENTE;
    }

    public static function pendentes(): int
    {
        return self::query()->where('status', self::PENDENTE)->count();
    }
}

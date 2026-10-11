<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vídeo da embalagem (KoraSync, 2026-10-11). Fica 90 dias. */
class OrderPackingVideo extends Model
{
    public const DIAS_GUARDADO = 90;

    public const MAX_MB = 60;

    protected $fillable = ['order_id', 'path', 'status', 'tamanho_original', 'tamanho', 'duracao_segundos', 'erro', 'expira_em'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'tamanho_mb' => round($this->tamanho / 1048576, 1),
            'duracao' => $this->duracao_segundos,
            'gravado_em' => $this->created_at?->toIso8601String(),
            'expira_em' => $this->expira_em?->toIso8601String(),
            'url' => "/admin/pedidos/{$this->order_id}/videos/{$this->id}",
            'download' => "/admin/pedidos/{$this->order_id}/videos/{$this->id}?baixar=1",
        ];
    }
}

<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um objeto dos Correios acompanhado pela loja (ver RegistroDeRastreio).
 * Os eventos ficam em rastreio_eventos.
 */
class Rastreio extends Model
{
    protected $table = 'rastreios';

    protected $fillable = [
        'order_id', 'codigo', 'situacao', 'ultimo_evento', 'ultimo_evento_em', 'previsao_entrega',
        'postado_em', 'entregue_em', 'encerrado_em', 'consultado_em', 'consultas', 'ultimo_erro',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_evento_em' => 'datetime',
            'previsao_entrega' => 'date',
            'postado_em' => 'datetime',
            'entregue_em' => 'datetime',
            'encerrado_em' => 'datetime',
            'consultado_em' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Mais recente primeiro, como os Correios mostram. */
    public function eventos(): HasMany
    {
        return $this->hasMany(RastreioEvento::class)->orderByDesc('ocorrido_em')->orderByDesc('id');
    }
}

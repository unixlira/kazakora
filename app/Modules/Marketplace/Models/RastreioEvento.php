<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um evento do SRO Rastro de um objeto acompanhado (ver Rastreio). */
class RastreioEvento extends Model
{
    protected $table = 'rastreio_eventos';

    protected $fillable = [
        'rastreio_id', 'codigo', 'tipo', 'ocorrido_em', 'descricao', 'detalhe',
        'unidade', 'cidade', 'uf', 'destino_cidade', 'destino_uf',
    ];

    protected function casts(): array
    {
        return ['ocorrido_em' => 'datetime'];
    }

    public function rastreio(): BelongsTo
    {
        return $this->belongsTo(Rastreio::class);
    }
}

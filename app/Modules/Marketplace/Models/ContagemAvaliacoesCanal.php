<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Avaliações reais de um anúncio na Shopee ou no Mercado Livre, lidas da API
 * por ContagemAvaliacoesCanais (pedido do Lira 2026-10-18).
 */
class ContagemAvaliacoesCanal extends Model
{
    protected $table = 'contagem_avaliacoes_canais';

    protected $fillable = [
        'channel',
        'external_id',
        'total',
        'nota_media',
        'conferido_em',
        'erro',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'nota_media' => 'float',
            'conferido_em' => 'datetime',
        ];
    }
}

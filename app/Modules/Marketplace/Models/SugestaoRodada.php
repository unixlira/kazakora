<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de cada rodada da rotina das sugestões: quando rodou e como foi
 * cada fonte (Meta Ads, Mercado Livre, Zoom). O board mostra a última, para
 * o Lira saber se a sugestão saiu com todos os dados ou sem alguma fonte.
 */
class SugestaoRodada extends Model
{
    protected $table = 'sugestoes_rodadas';

    protected $fillable = ['dia', 'iniciada_em', 'terminada_em', 'total', 'fontes', 'erro'];

    protected function casts(): array
    {
        return [
            'dia' => 'date',
            'iniciada_em' => 'datetime',
            'terminada_em' => 'datetime',
            'fontes' => 'array',
            'total' => 'integer',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma coleta do ranking de produtos do Kalodata (ver MaisVendidosTikTok).
 */
class KalodataRanking extends Model
{
    protected $table = 'kalodata_rankings';

    protected $fillable = ['periodo', 'completo', 'total', 'produtos', 'coletado_em'];

    protected function casts(): array
    {
        return [
            'completo' => 'boolean',
            'total' => 'integer',
            'produtos' => 'array',
            'coletado_em' => 'datetime',
        ];
    }
}

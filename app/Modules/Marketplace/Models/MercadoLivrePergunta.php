<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

class MercadoLivrePergunta extends Model
{
    protected $table = 'ml_perguntas';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'precisa_humano' => 'boolean',
            'perguntada_em' => 'datetime',
            'rascunho_em' => 'datetime',
            'respondida_em' => 'datetime',
        ];
    }
}

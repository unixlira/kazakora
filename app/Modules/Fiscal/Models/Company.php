<?php

namespace App\Modules\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    public const REGIME_SIMPLES_NACIONAL = 'simples_nacional';

    public const REGIME_MEI = 'mei';

    public const REGIME_LUCRO_PRESUMIDO = 'lucro_presumido';

    public const REGIME_LUCRO_REAL = 'lucro_real';

    protected $fillable = [
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'inscricao_estadual',
        'inscricao_municipal',
        'regime_tributario',
        'cnae',
        'logo_path',
        'phone',
        'email',
        'zip',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
    ];
}

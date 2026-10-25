<?php

namespace App\Modules\Checkout\Models;

use App\Models\IbgeMunicipio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Onde uma FreteRegiao vale: uma UF, um município do IBGE, um distrito de um
 * município (2026-10-25: distrito + CEP ou faixa) ou uma faixa de CEP.
 */
class FreteRegiaoLocal extends Model
{
    protected $table = 'frete_regiao_locais';

    protected $fillable = ['frete_regiao_id', 'uf', 'municipio_id', 'distrito_id', 'distrito', 'cep_inicio', 'cep_fim'];

    public function regiao(): BelongsTo
    {
        return $this->belongsTo(FreteRegiao::class, 'frete_regiao_id');
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(IbgeMunicipio::class, 'municipio_id');
    }
}

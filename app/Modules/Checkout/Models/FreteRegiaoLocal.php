<?php

namespace App\Modules\Checkout\Models;

use App\Models\IbgeMunicipio;
use App\Modules\Checkout\Support\FreteRegioes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Onde uma FreteRegiao vale. Um alvo por linha: faixa de CEP (cep_inicio a
 * cep_fim, 8 dígitos), município do IBGE (municipio_id) ou estado (uf).
 */
class FreteRegiaoLocal extends Model
{
    protected $table = 'frete_regiao_locais';

    protected $fillable = ['frete_regiao_id', 'uf', 'municipio_id', 'cep_inicio', 'cep_fim'];

    protected function casts(): array
    {
        return ['municipio_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (FreteRegiaoLocal $local) {
            $local->uf = $local->uf ? strtoupper($local->uf) : null;
            $local->cep_inicio = $local->cep_inicio ? preg_replace('/\D/', '', $local->cep_inicio) : null;
            $local->cep_fim = $local->cep_fim ? preg_replace('/\D/', '', $local->cep_fim) : ($local->cep_inicio ?: null);
        });
        static::saved(fn () => FreteRegioes::esquecer());
        static::deleted(fn () => FreteRegioes::esquecer());
    }

    public function regiao(): BelongsTo
    {
        return $this->belongsTo(FreteRegiao::class, 'frete_regiao_id');
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(IbgeMunicipio::class, 'municipio_id');
    }
}

<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Meta ADS (2026-10-18): conjunto de anúncios da campanha, como no
 * Gerenciador da Meta (ex.: "Árvore de Natal" com 3 anúncios). Vira um ad
 * set na Meta. O público é o da campanha, a menos que publico_proprio
 * esteja ligado; o orçamento só vale quando a campanha divide o orçamento
 * por conjunto.
 */
class MetaAdsConjunto extends Model
{
    protected $table = 'meta_ads_conjuntos';

    protected $fillable = [
        'campanha_id',
        'ordem',
        'nome',
        'orcamento_diario',
        'publico_proprio',
        'idade_min',
        'idade_max',
        'genero',
        'localizacoes',
        'meta_adset_id',
        'ultimo_erro',
    ];

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'orcamento_diario' => 'decimal:2',
            'publico_proprio' => 'boolean',
            'idade_min' => 'integer',
            'idade_max' => 'integer',
            'localizacoes' => 'array',
        ];
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(MetaAdsCampanha::class, 'campanha_id');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(MetaAdsVideo::class, 'conjunto_id')->orderBy('ordem')->orderBy('id');
    }

    /**
     * Público que vai para a Meta: o próprio ou o da campanha.
     *
     * @return array{idade_min: int, idade_max: int, genero: string, localizacoes: array}
     */
    public function publico(MetaAdsCampanha $campanha): array
    {
        $fonte = $this->publico_proprio ? $this : $campanha;

        return [
            'idade_min' => (int) ($fonte->idade_min ?? $campanha->idade_min),
            'idade_max' => (int) ($fonte->idade_max ?? $campanha->idade_max),
            'genero' => (string) ($fonte->genero ?? $campanha->genero),
            'localizacoes' => $fonte->localizacoes ?? [],
        ];
    }
}

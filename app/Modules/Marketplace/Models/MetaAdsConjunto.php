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
        'adicionado_depois',
        'enviando',
        'ativado_em',
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
            'adicionado_depois' => 'boolean',
            'enviando' => 'boolean',
            'ativado_em' => 'datetime',
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

    /** Ad set e todos os anúncios deste conjunto já criados na Meta. */
    public function envioCompleto(MetaAdsCampanha $campanha): bool
    {
        $anuncios = $campanha->videos->where('conjunto_id', $this->id);

        return filled($this->meta_adset_id)
            && $anuncios->isNotEmpty()
            && $anuncios->every(fn (MetaAdsVideo $v) => $v->envioCompleto());
    }

    /**
     * Conjunto novo em campanha que já está na Meta (pedido do Lira
     * 2026-10-19) e que ainda não terminou de subir.
     */
    public function novoPendente(MetaAdsCampanha $campanha): bool
    {
        return $this->adicionado_depois && ! $this->envioCompleto($campanha);
    }

    /** Conjunto novo que ainda não tem ad set na Meta: muda nome, público e anúncios à vontade. */
    public function aceitaMudanca(MetaAdsCampanha $campanha): bool
    {
        return $this->adicionado_depois
            && ! $this->enviando
            && blank($this->meta_adset_id)
            && $campanha->podeAdicionarConjunto();
    }

    public function podeEnviar(MetaAdsCampanha $campanha): bool
    {
        return $this->adicionado_depois
            && ! $this->enviando
            && ! $this->envioCompleto($campanha)
            && $campanha->podeAdicionarConjunto();
    }

    /**
     * Conjunto novo já na Meta, ainda pausado, em campanha que está rodando
     * (ou que o Lira toca no Gerenciador). Em campanha pausada, o conjunto
     * liga junto quando a campanha for ativada.
     */
    public function podeAtivar(MetaAdsCampanha $campanha): bool
    {
        return $this->adicionado_depois
            && ! $this->enviando
            && $this->ativado_em === null
            && $this->envioCompleto($campanha)
            && in_array($campanha->status, [MetaAdsCampanha::STATUS_ATIVA, MetaAdsCampanha::STATUS_GERENCIADOR], true);
    }
}

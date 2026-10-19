<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    /**
     * Anúncios acrescentados depois do envio da campanha que ainda não
     * terminaram de subir (em conjunto novo ou em conjunto que já existia).
     *
     * @return Collection<int, MetaAdsVideo>
     */
    public function anunciosNovosPendentes(MetaAdsCampanha $campanha): Collection
    {
        return $campanha->videos
            ->where('conjunto_id', $this->id)
            ->filter(fn (MetaAdsVideo $v) => ($v->adicionado_depois || $this->adicionado_depois) && ! $v->envioCompleto())
            ->values();
    }

    /** Tem conjunto novo ou anúncio novo esperando o envio. */
    public function temEnvioPendente(MetaAdsCampanha $campanha): bool
    {
        return ($this->adicionado_depois && blank($this->meta_adset_id))
            || $this->anunciosNovosPendentes($campanha)->isNotEmpty();
    }

    /** Conjunto novo que ainda não tem ad set na Meta: muda nome e público, ou sai. */
    public function aceitaMudanca(MetaAdsCampanha $campanha): bool
    {
        return $this->adicionado_depois
            && ! $this->enviando
            && blank($this->meta_adset_id)
            && $campanha->podeAdicionarConjunto();
    }

    /** Acrescentar anúncio novo: qualquer conjunto de campanha que já está na Meta. */
    public function aceitaAnuncioNovo(MetaAdsCampanha $campanha): bool
    {
        return ! $this->enviando && $campanha->podeAdicionarConjunto();
    }

    public function podeEnviar(MetaAdsCampanha $campanha): bool
    {
        return ! $this->enviando
            && $campanha->podeAdicionarConjunto()
            && $this->temEnvioPendente($campanha);
    }

    /**
     * Anúncios novos já na Meta e ainda pausados (ou o conjunto novo
     * inteiro), em campanha que está rodando ou que o Lira toca no
     * Gerenciador. Em campanha pausada, liga junto quando ela for ativada.
     *
     * @return Collection<int, MetaAdsVideo>
     */
    public function anunciosParaAtivar(MetaAdsCampanha $campanha): Collection
    {
        return $campanha->videos
            ->where('conjunto_id', $this->id)
            ->filter(fn (MetaAdsVideo $v) => ($v->adicionado_depois || $this->adicionado_depois) && $v->envioCompleto() && $v->ativado_em === null)
            ->values();
    }

    public function podeAtivar(MetaAdsCampanha $campanha): bool
    {
        return ! $this->enviando
            && in_array($campanha->status, [MetaAdsCampanha::STATUS_ATIVA, MetaAdsCampanha::STATUS_GERENCIADOR], true)
            && ! $this->temEnvioPendente($campanha)
            && filled($this->meta_adset_id)
            && (($this->adicionado_depois && $this->ativado_em === null) || $this->anunciosParaAtivar($campanha)->isNotEmpty());
    }
}

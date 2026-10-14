<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta ADS (2026-10-13): um vídeo enviado pelo admin para uma campanha.
 * Na Meta ele vira vídeo da conta (advideos), um conjunto de anúncios com a
 * sua parte do orçamento diário, um criativo e um anúncio. O arquivo fica
 * no disco local (privado) e é apagado junto com o rascunho.
 */
class MetaAdsVideo extends Model
{
    protected $table = 'meta_ads_videos';

    protected $fillable = [
        'campanha_id',
        'ordem',
        'nome_original',
        'path',
        'tamanho',
        'duracao_segundos',
        'orcamento_diario',
        'meta_video_id',
        'meta_thumbnail_url',
        'meta_adset_id',
        'meta_creative_id',
        'meta_ad_id',
        'ultimo_erro',
    ];

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'tamanho' => 'integer',
            'duracao_segundos' => 'decimal:2',
            'orcamento_diario' => 'decimal:2',
        ];
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(MetaAdsCampanha::class, 'campanha_id');
    }

    public function temObjetoNoMeta(): bool
    {
        return filled($this->meta_video_id) || filled($this->meta_adset_id)
            || filled($this->meta_creative_id) || filled($this->meta_ad_id);
    }

    public function envioCompleto(): bool
    {
        return filled($this->meta_adset_id) && filled($this->meta_creative_id) && filled($this->meta_ad_id);
    }

    /** Em que pé está o vídeo na Meta, em palavras para a tela. */
    public function etapa(): string
    {
        return match (true) {
            $this->envioCompleto() => 'Anúncio criado (pausado)',
            filled($this->ultimo_erro) => 'Erro',
            filled($this->meta_creative_id) => 'Criativo pronto',
            filled($this->meta_thumbnail_url) => 'Vídeo processado',
            filled($this->meta_video_id) => 'Meta processando o vídeo',
            default => 'Ainda não enviado',
        };
    }
}

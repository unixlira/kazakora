<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta ADS (2026-10-13): um anúncio da campanha, com o arquivo enviado pelo
 * admin. Desde 2026-10-18 pode ser vídeo ou imagem e ter produto, link,
 * texto, título, descrição e botão próprios (vazio = padrão da campanha),
 * para várias peças diferentes (ex.: 3 árvores de Natal) na mesma campanha.
 *
 * Na Meta ele vira mídia da conta (advideos ou adimages), criativo e
 * anúncio, dentro do conjunto da campanha (conjunto único) ou do seu próprio
 * conjunto (estrutura por anúncio). O arquivo fica no disco local (privado)
 * e é apagado junto com o rascunho.
 */
class MetaAdsVideo extends Model
{
    protected $table = 'meta_ads_videos';

    public const TIPO_VIDEO = 'video';

    public const TIPO_IMAGEM = 'imagem';

    protected $fillable = [
        'campanha_id',
        'ordem',
        'tipo',
        'nome_original',
        'path',
        'tamanho',
        'duracao_segundos',
        'produto_id',
        'link_destino',
        'texto_principal',
        'titulo',
        'descricao',
        'chamada',
        'orcamento_diario',
        'meta_video_id',
        'meta_image_hash',
        'meta_thumbnail_url',
        'meta_adset_id',
        'meta_creative_id',
        'meta_ad_id',
        'ultimo_erro',
    ];

    protected $attributes = [
        'tipo' => self::TIPO_VIDEO,
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

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produto_id');
    }

    public function ehImagem(): bool
    {
        return $this->tipo === self::TIPO_IMAGEM;
    }

    /**
     * Texto que vai no criativo: o do anúncio ou, vazio, o da campanha.
     *
     * @return array{link: string, texto: ?string, titulo: ?string, descricao: ?string, chamada: string}
     */
    public function criativo(MetaAdsCampanha $campanha): array
    {
        $ou = fn (?string $proprio, ?string $padrao) => filled($proprio) ? $proprio : $padrao;

        return [
            'link' => (string) $ou($this->link_destino, $campanha->link_destino),
            'texto' => $ou($this->texto_principal, $campanha->texto_principal),
            'titulo' => $ou($this->titulo, $campanha->titulo),
            'descricao' => $ou($this->descricao, $campanha->descricao),
            'chamada' => (string) $ou($this->chamada, $campanha->chamada),
        ];
    }

    public function temObjetoNoMeta(): bool
    {
        return filled($this->meta_video_id) || filled($this->meta_image_hash) || filled($this->meta_adset_id)
            || filled($this->meta_creative_id) || filled($this->meta_ad_id);
    }

    /** Mídia já está na Meta e pronta para virar criativo. */
    public function midiaPronta(): bool
    {
        return $this->ehImagem() ? filled($this->meta_image_hash) : filled($this->meta_thumbnail_url);
    }

    public function envioCompleto(): bool
    {
        return filled($this->meta_adset_id) && filled($this->meta_creative_id) && filled($this->meta_ad_id);
    }

    /** Em que pé está o anúncio na Meta, em palavras para a tela. */
    public function etapa(): string
    {
        return match (true) {
            $this->envioCompleto() => 'Anúncio criado (pausado)',
            filled($this->ultimo_erro) => 'Erro',
            filled($this->meta_creative_id) => 'Criativo pronto',
            $this->ehImagem() && filled($this->meta_image_hash) => 'Imagem enviada',
            filled($this->meta_thumbnail_url) => 'Vídeo processado',
            filled($this->meta_video_id) => 'Meta processando o vídeo',
            default => 'Ainda não enviado',
        };
    }
}

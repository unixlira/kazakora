<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Meta ADS (2026-10-13): campanha montada no admin e enviada pela
 * Marketing API da Meta (App\Services\Meta\MetaAdsService). Cada anúncio
 * (MetaAdsVideo: vídeo ou imagem, com produto e texto próprios) entra num
 * conjunto conforme a estrutura (2026-10-18): todos no mesmo conjunto, como
 * no Gerenciador da Meta, ou um conjunto por anúncio com o orçamento
 * dividido igual. Tudo nasce PAUSADO na Meta; ativar é um passo separado,
 * com confirmação do gasto na tela.
 *
 * Os ids meta_* são gravados a cada passo do envio: se a Meta recusar no
 * meio, o reenvio continua do passo que faltou em vez de duplicar campanha.
 */
class MetaAdsCampanha extends Model
{
    protected $table = 'meta_ads_campanhas';

    public const STATUS_RASCUNHO = 'rascunho';

    public const STATUS_ENVIANDO = 'enviando';

    public const STATUS_ENVIADA_PAUSADA = 'enviada_pausada';

    public const STATUS_ATIVA = 'ativa';

    public const STATUS_ERRO = 'erro';

    public const STATUS_ROTULOS = [
        self::STATUS_RASCUNHO => 'Rascunho',
        self::STATUS_ENVIANDO => 'Enviando',
        self::STATUS_ENVIADA_PAUSADA => 'Enviada (pausada)',
        self::STATUS_ATIVA => 'Ativa',
        self::STATUS_ERRO => 'Erro no envio',
    ];

    public const OBJETIVOS = [
        'OUTCOME_SALES' => 'Vendas',
        'OUTCOME_TRAFFIC' => 'Tráfego para o site',
        'OUTCOME_ENGAGEMENT' => 'Engajamento',
        'OUTCOME_AWARENESS' => 'Reconhecimento',
    ];

    /** Um conjunto com todos os anúncios: a Meta leva a verba para o que rende mais. */
    public const ESTRUTURA_CONJUNTO_UNICO = 'conjunto_unico';

    /** Um conjunto por anúncio, orçamento dividido igual (teste justo entre eles). */
    public const ESTRUTURA_POR_ANUNCIO = 'por_anuncio';

    public const ESTRUTURAS = [
        self::ESTRUTURA_CONJUNTO_UNICO => 'Um conjunto com todos os anúncios',
        self::ESTRUTURA_POR_ANUNCIO => 'Um conjunto por anúncio (orçamento dividido igual)',
    ];

    public const GENEROS = [
        'todos' => 'Todos',
        'masculino' => 'Masculino',
        'feminino' => 'Feminino',
    ];

    public const CHAMADAS = [
        'SHOP_NOW' => 'Comprar agora',
        'LEARN_MORE' => 'Saiba mais',
        'ORDER_NOW' => 'Peça agora',
        'BUY_NOW' => 'Compre já',
        'GET_OFFER' => 'Ver oferta',
        'SEE_MORE' => 'Ver mais',
        'CONTACT_US' => 'Fale conosco',
    ];

    protected $fillable = [
        'nome',
        'objetivo',
        'estrutura',
        'orcamento_diario',
        'inicio',
        'fim',
        'idade_min',
        'idade_max',
        'genero',
        'localizacoes',
        'produto_id',
        'link_destino',
        'texto_principal',
        'titulo',
        'descricao',
        'chamada',
        'status',
        'meta_campaign_id',
        'meta_adset_id',
        'ultimo_erro',
        'enviada_em',
        'ativada_em',
        'user_id',
    ];

    protected $appends = ['objetivo_rotulo', 'status_rotulo'];

    protected function casts(): array
    {
        return [
            'orcamento_diario' => 'decimal:2',
            'inicio' => 'date:Y-m-d',
            'fim' => 'date:Y-m-d',
            'idade_min' => 'integer',
            'idade_max' => 'integer',
            'localizacoes' => 'array',
            'enviada_em' => 'datetime',
            'ativada_em' => 'datetime',
        ];
    }

    /** Teto de anúncios (vídeos ou imagens) por campanha. */
    public const MAX_VIDEOS = 10;

    /** Menor orçamento diário por conjunto de anúncios que a tela aceita (R$). */
    public const MINIMO_POR_VIDEO = 6.0;

    public function conjuntoUnico(): bool
    {
        return $this->estrutura === self::ESTRUTURA_CONJUNTO_UNICO;
    }

    /** Quantos conjuntos a campanha terá na Meta (o orçamento se divide entre eles). */
    public function quantidadeDeConjuntos(): int
    {
        $anuncios = $this->videos->count();

        return $this->conjuntoUnico() ? min($anuncios, 1) : $anuncios;
    }

    /**
     * Orçamento diário de cada anúncio em centavos: no conjunto único todos
     * dividem a mesma verba (a Meta distribui), então cada um mostra o total.
     *
     * @return list<int>
     */
    public function orcamentoPorAnuncio(): array
    {
        $quantidade = $this->videos->count();

        if ($this->conjuntoUnico()) {
            return array_fill(0, $quantidade, (int) round((float) $this->orcamento_diario * 100));
        }

        return self::dividirOrcamento((float) $this->orcamento_diario, $quantidade);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(MetaAdsVideo::class, 'campanha_id')->orderBy('ordem')->orderBy('id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produto_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getObjetivoRotuloAttribute(): string
    {
        return self::OBJETIVOS[$this->objetivo] ?? (string) $this->objetivo;
    }

    public function getStatusRotuloAttribute(): string
    {
        return self::STATUS_ROTULOS[$this->status] ?? (string) $this->status;
    }

    /**
     * Orçamento diário dividido igualmente entre os vídeos, em centavos
     * (o que a Meta pede). Centavo que sobra da divisão vai para os
     * primeiros, para a soma bater exatamente com o total.
     *
     * @return list<int>
     */
    public static function dividirOrcamento(float $total, int $quantidade): array
    {
        if ($quantidade < 1) {
            return [];
        }

        $centavos = (int) round($total * 100);
        $parte = intdiv($centavos, $quantidade);
        $sobra = $centavos - $parte * $quantidade;

        return array_map(fn (int $i) => $parte + ($i < $sobra ? 1 : 0), range(0, $quantidade - 1));
    }

    /** Já existe algum objeto criado na Meta para esta campanha? */
    public function temObjetoNoMeta(): bool
    {
        if (filled($this->meta_campaign_id) || filled($this->meta_adset_id)) {
            return true;
        }

        return $this->videos->contains(fn (MetaAdsVideo $v) => $v->temObjetoNoMeta());
    }

    /** Campanha e todos os anúncios com conjunto, criativo e anúncio na Meta. */
    public function envioCompleto(): bool
    {
        return filled($this->meta_campaign_id)
            && $this->videos->isNotEmpty()
            && $this->videos->every(fn (MetaAdsVideo $v) => $v->envioCompleto());
    }

    public function podeEditar(): bool
    {
        return in_array($this->status, [self::STATUS_RASCUNHO, self::STATUS_ERRO], true)
            && ! $this->temObjetoNoMeta();
    }

    public function podeMexerNosVideos(): bool
    {
        return $this->podeEditar();
    }

    public function podeEnviar(): bool
    {
        return in_array($this->status, [self::STATUS_RASCUNHO, self::STATUS_ERRO], true);
    }

    public function podeExcluir(): bool
    {
        return $this->status === self::STATUS_RASCUNHO && ! $this->temObjetoNoMeta();
    }

    public function podeAtivar(): bool
    {
        return $this->status === self::STATUS_ENVIADA_PAUSADA && $this->envioCompleto();
    }

    public function podePausar(): bool
    {
        return $this->status === self::STATUS_ATIVA && $this->envioCompleto();
    }
}

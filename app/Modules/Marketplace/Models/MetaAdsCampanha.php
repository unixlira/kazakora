<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Meta ADS (2026-10-13): campanha montada no admin e enviada pela
 * Marketing API da Meta (App\Services\Meta\MetaAdsService). Desde
 * 2026-10-18 segue a estrutura do Gerenciador: Campanha > vários conjuntos
 * (MetaAdsConjunto, cada um com nome e, opcional, público próprio) > vários
 * anúncios em cada (MetaAdsVideo: vídeo ou imagem, com produto e texto
 * próprios). O orçamento é da campanha (a Meta distribui entre os
 * conjuntos) ou de cada conjunto. Tudo nasce PAUSADO na Meta; ativar é um
 * passo separado, com confirmação do gasto na tela.
 *
 * Os ids meta_* são gravados a cada passo do envio: se a Meta recusar no
 * meio, o reenvio continua do passo que faltou em vez de duplicar campanha.
 *
 * Desde 2026-10-20 a campanha que já está na Meta pode ser editada (nome e
 * orçamento) e excluída pelo admin. Excluída na Meta sai da tela mas fica
 * no banco (SoftDeletes), como o anúncio; rascunho sai de vez (forceDelete).
 */
class MetaAdsCampanha extends Model
{
    use SoftDeletes;

    protected $table = 'meta_ads_campanhas';

    public const STATUS_RASCUNHO = 'rascunho';

    public const STATUS_ENVIANDO = 'enviando';

    public const STATUS_ENVIADA_PAUSADA = 'enviada_pausada';

    public const STATUS_ATIVA = 'ativa';

    public const STATUS_ERRO = 'erro';

    /**
     * O Lira terminou ou mexeu na campanha direto no Gerenciador da Meta
     * (2026-10-18, Árvore de Natal). O KazaKora não envia, ativa nem pausa
     * mais nada dela: só mostra o resultado e o link do Gerenciador.
     */
    public const STATUS_GERENCIADOR = 'gerenciador';

    public const STATUS_ROTULOS = [
        self::STATUS_RASCUNHO => 'Rascunho',
        self::STATUS_ENVIANDO => 'Enviando',
        self::STATUS_ENVIADA_PAUSADA => 'Enviada (pausada)',
        self::STATUS_ATIVA => 'Ativa',
        self::STATUS_ERRO => 'Erro no envio',
        self::STATUS_GERENCIADOR => 'Assumida no Gerenciador',
    ];

    /** Opções além do texto, título e descrição principais (a Meta aceita 5 de cada no total). */
    public const MAX_TEXTOS_ALTERNATIVOS = 4;

    public const OBJETIVOS = [
        'OUTCOME_SALES' => 'Vendas',
        'OUTCOME_TRAFFIC' => 'Tráfego para o site',
        'OUTCOME_ENGAGEMENT' => 'Engajamento',
        'OUTCOME_AWARENESS' => 'Reconhecimento',
    ];

    /** Orçamento da campanha (Advantage): a Meta leva a verba para o conjunto que rende mais. */
    public const ORCAMENTO_CAMPANHA = 'campanha';

    /** Orçamento por conjunto: cada conjunto gasta o seu valor. */
    public const ORCAMENTO_CONJUNTO = 'conjunto';

    public const ORCAMENTO_TIPOS = [
        self::ORCAMENTO_CAMPANHA => 'Orçamento da campanha (recomendado)',
        self::ORCAMENTO_CONJUNTO => 'Orçamento por conjunto',
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
        'orcamento_tipo',
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
        'textos_alternativos',
        'chamada',
        'status',
        'meta_campaign_id',
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
            'textos_alternativos' => 'array',
            'enviada_em' => 'datetime',
            'ativada_em' => 'datetime',
        ];
    }

    /** Teto de conjuntos por campanha (a Meta aceita 200; acima disso a tela vira bagunça). */
    public const MAX_CONJUNTOS = 10;

    /** Teto de anúncios (vídeos ou imagens) por campanha, somando os conjuntos. */
    public const MAX_VIDEOS = 50;

    /** Acima disso no mesmo conjunto a Meta divide demais a entrega (só aviso). */
    public const RECOMENDADO_POR_CONJUNTO = 6;

    /** Menor orçamento diário por conjunto de anúncios que a tela aceita (R$). */
    public const MINIMO_POR_CONJUNTO = 6.0;

    public function orcamentoDaCampanha(): bool
    {
        return $this->orcamento_tipo !== self::ORCAMENTO_CONJUNTO;
    }

    public function conjuntos(): HasMany
    {
        return $this->hasMany(MetaAdsConjunto::class, 'campanha_id')->orderBy('ordem')->orderBy('id');
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
     * Orçamento diário dividido igualmente entre N partes, em centavos
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
        if (filled($this->meta_campaign_id) || $this->conjuntos->contains(fn (MetaAdsConjunto $c) => filled($c->meta_adset_id))) {
            return true;
        }

        return $this->videos->contains(fn (MetaAdsVideo $v) => $v->temObjetoNoMeta());
    }

    /**
     * Campanha e todos os anúncios com conjunto, criativo e anúncio na Meta.
     * Conjunto ou anúncio novo que ainda não subiu (acrescentado depois do
     * envio) não conta: a campanha segue podendo pausar e ativar enquanto
     * ele é montado.
     */
    public function envioCompleto(): bool
    {
        $pendentes = $this->conjuntosNovosPendentes()->pluck('id');
        $conjuntos = $this->conjuntos->whereNotIn('id', $pendentes);
        $videos = $this->videos->whereNotIn('conjunto_id', $pendentes)->reject(fn (MetaAdsVideo $v) => $v->adicionado_depois && ! $v->envioCompleto());

        return filled($this->meta_campaign_id)
            && $videos->isNotEmpty()
            && $conjuntos->every(fn (MetaAdsConjunto $c) => filled($c->meta_adset_id))
            && $videos->every(fn (MetaAdsVideo $v) => $v->envioCompleto());
    }

    /** @return Collection<int, MetaAdsConjunto> */
    public function conjuntosNovosPendentes(): Collection
    {
        return $this->conjuntos->filter(fn (MetaAdsConjunto $c) => $c->novoPendente($this))->values();
    }

    /**
     * Conjunto novo dentro de campanha que já está na Meta (pedido do Lira
     * 2026-10-19). Vale também para campanha assumida no Gerenciador: o
     * conjunto sobe sozinho, pausado, sem reenviar o resto.
     */
    public function podeAdicionarConjunto(): bool
    {
        return filled($this->meta_campaign_id)
            && in_array($this->status, [self::STATUS_ENVIADA_PAUSADA, self::STATUS_ATIVA, self::STATUS_GERENCIADOR], true);
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

    /**
     * Opções extras de texto, sem vazio nem repetida.
     *
     * @return array{textos: list<string>, titulos: list<string>, descricoes: list<string>}
     */
    public function textosAlternativos(): array
    {
        $guardado = $this->textos_alternativos ?? [];
        $limpar = fn ($lista) => array_values(array_slice(array_unique(array_filter(
            array_map(fn ($t) => trim((string) $t), (array) $lista),
            fn (string $t) => $t !== '',
        )), 0, self::MAX_TEXTOS_ALTERNATIVOS));

        return [
            'textos' => $limpar($guardado['textos'] ?? []),
            'titulos' => $limpar($guardado['titulos'] ?? []),
            'descricoes' => $limpar($guardado['descricoes'] ?? []),
        ];
    }
}

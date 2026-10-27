<?php

namespace App\Services\Meta;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsConjunto;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Modules\Marketplace\Support\FormatoDeVideo;
use App\Support\PixelMeta;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Meta ADS (2026-10-13): envio de campanhas pela Marketing API.
 *
 * Estrutura na Meta, igual ao Gerenciador (2026-10-18): Campanha > vários
 * conjuntos (MetaAdsConjunto, com nome e público próprio ou o da campanha)
 * > vários anúncios em cada. Orçamento:
 * - da campanha (Advantage campaign budget, padrão): daily_budget e
 *   bid_strategy vão na campanha e a Meta distribui entre os conjuntos;
 * - por conjunto: cada ad set com o seu daily_budget e a campanha com
 *   is_adset_budget_sharing_enabled=false (obrigatório desde a v24).
 * A Meta não aceita os dois ao mesmo tempo. Cada anúncio tem a sua mídia
 * (vídeo ou imagem), criativo e texto próprio (ou o padrão da campanha).
 * Criação em chamadas sequenciais (não batch): cada id é gravado assim que
 * sai, e o reenvio continua do ponto que falhou.
 *
 * Ordem (2026-10-18, Árvore de Natal): mídias, criativos e só então
 * campanha, conjuntos e anúncios. O criativo é o passo que a Meta recusa
 * quando o app está em modo de desenvolvimento (erro 1885183); criando ele
 * primeiro, a recusa não deixa campanha e conjunto vazios na conta.
 *
 * Regras que não podem quebrar:
 * - Tudo é criado PAUSADO (campanha, conjuntos e anúncios). Ativar é outro
 *   passo (alterarStatus), disparado só com confirmação do gasto na tela.
 * - O token vai no cabeçalho Authorization, nunca na URL, e nunca é logado
 *   nem aparece em mensagem de erro.
 * - Cada id é salvo assim que a Meta devolve: se um passo falhar, o
 *   reenvio pula o que já existe e continua do passo que faltou.
 */
class MetaAdsService
{
    /** Objetivo da campanha => meta de otimização do conjunto. */
    public const OTIMIZACAO = [
        'OUTCOME_SALES' => 'OFFSITE_CONVERSIONS',
        'OUTCOME_TRAFFIC' => 'LINK_CLICKS',
        'OUTCOME_ENGAGEMENT' => 'POST_ENGAGEMENT',
        'OUTCOME_AWARENESS' => 'REACH',
    ];

    /** Tipos de ação de compra, do mais completo para o mais restrito. */
    private const ACOES_DE_COMPRA = ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase'];

    private const FUSO = 'America/Sao_Paulo';

    public function configurado(): bool
    {
        return $this->faltando() === [];
    }

    /**
     * Variáveis obrigatórias que estão vazias (nomes do .env, sem valor).
     *
     * @return list<string>
     */
    public function faltando(): array
    {
        $faltando = [];

        foreach (['access_token' => 'META_ADS_ACCESS_TOKEN', 'ad_account_id' => 'META_ADS_AD_ACCOUNT_ID', 'page_id' => 'META_ADS_PAGE_ID'] as $chave => $env) {
            if (blank(config("services.meta_ads.{$chave}"))) {
                $faltando[] = $env;
            }
        }

        return $faltando;
    }

    public function pixelConfigurado(): bool
    {
        return filled(PixelMeta::id());
    }

    /**
     * O que impede o envio desta campanha (lista vazia = pode enviar).
     *
     * @return list<string>
     */
    public function problemasParaEnviar(MetaAdsCampanha $campanha): array
    {
        $problemas = [];

        if (! $this->configurado()) {
            $problemas[] = 'Meta não configurado.';
        }

        if ($campanha->objetivo === 'OUTCOME_SALES' && ! $this->pixelConfigurado()) {
            $problemas[] = 'Objetivo Vendas precisa do pixel da Meta (Configurações › Pixel da Meta).';
        }

        if (! isset(self::OTIMIZACAO[$campanha->objetivo])) {
            $problemas[] = 'Objetivo inválido.';
        }

        $campanha->loadMissing(['conjuntos', 'videos']);
        $real = fn (float $valor) => number_format($valor, 2, ',', '.');
        $comAnuncio = $campanha->conjuntos->filter(fn (MetaAdsConjunto $c) => $campanha->videos->contains('conjunto_id', $c->id));

        if ($campanha->videos->isEmpty()) {
            $problemas[] = 'Envie pelo menos um vídeo ou imagem.';
        }

        foreach ($campanha->conjuntos as $conjunto) {
            if (! $comAnuncio->contains($conjunto)) {
                $problemas[] = "O conjunto {$conjunto->nome} não tem anúncio. Adicione um ou remova o conjunto.";
            } elseif (! $campanha->orcamentoDaCampanha() && (float) $conjunto->orcamento_diario < MetaAdsCampanha::MINIMO_POR_CONJUNTO) {
                $problemas[] = "Orçamento baixo no conjunto {$conjunto->nome}: o mínimo é R$ {$real(MetaAdsCampanha::MINIMO_POR_CONJUNTO)} por dia.";
            }
        }

        $quantidade = $comAnuncio->count();
        if ($campanha->orcamentoDaCampanha() && $quantidade > 0 && (float) $campanha->orcamento_diario < MetaAdsCampanha::MINIMO_POR_CONJUNTO * $quantidade) {
            $problemas[] = $quantidade === 1
                ? "Orçamento baixo: o mínimo é R$ {$real(MetaAdsCampanha::MINIMO_POR_CONJUNTO)} por dia."
                : "Orçamento baixo: com {$quantidade} conjuntos, o mínimo é R$ {$real(MetaAdsCampanha::MINIMO_POR_CONJUNTO * $quantidade)} por dia (R$ {$real(MetaAdsCampanha::MINIMO_POR_CONJUNTO)} por conjunto).";
        }

        if ($campanha->videos->contains(fn (MetaAdsVideo $v) => ! $campanha->conjuntos->contains('id', $v->conjunto_id))) {
            $problemas[] = 'Há anúncio fora de conjunto. Abra a edição e salve de novo.';
        }

        foreach ($campanha->videos as $video) {
            $nome = $this->rotuloDoAnuncio($video);
            $criativo = $video->criativo($campanha);

            if (blank($video->ehImagem() ? $video->meta_image_hash : $video->meta_video_id) && ! Storage::disk('local')->exists($video->path)) {
                $problemas[] = "O arquivo do {$nome} sumiu do servidor. Tire e envie de novo.";
            }
            if (blank($criativo['texto'])) {
                $problemas[] = "Falta o texto principal do {$nome} (ou um texto padrão na campanha).";
            }
            if (blank($criativo['titulo'])) {
                $problemas[] = "Falta o título do {$nome} (ou um título padrão na campanha).";
            }
        }

        return $problemas;
    }

    /** Resultado de avancarEnvio(). */
    public const ENVIO_PRONTO = 'pronto';

    /** Subiu um vídeo; ainda há trabalho, pode chamar de novo já. */
    public const ENVIO_CONTINUAR = 'continuar';

    /** A Meta ainda processa algum vídeo; chamar de novo daqui a pouco. */
    public const ENVIO_AGUARDANDO = 'aguardando';

    /**
     * Avança o envio da campanha para a Meta, tudo PAUSADO, continuando do
     * passo que faltou. Sobe no máximo um vídeo por chamada, para cada
     * rodada da fila caber no tempo limite do worker.
     *
     * Com $somente (conjunto novo em campanha que já está na Meta, pedido do
     * Lira 2026-10-19), sobe só esse conjunto e os anúncios dele, dentro da
     * campanha que já existe, e não mexe no status da campanha: ela pode
     * estar gastando. O erro fica no conjunto.
     *
     * @return self::ENVIO_*
     *
     * @throws MetaAdsException
     */
    public function avancarEnvio(MetaAdsCampanha $campanha, ?MetaAdsConjunto $somente = null): string
    {
        $campanha->loadMissing(['conjuntos', 'videos']);
        $passo = 'campanha';
        $video = null;
        $conjuntos = $somente ? $campanha->conjuntos->where('id', $somente->id)->values() : $campanha->conjuntos;
        $videos = $somente ? $campanha->videos->where('conjunto_id', $somente->id)->values() : $campanha->videos;
        $alvo = $somente ? $conjuntos->first() : null;

        if ($somente && (! $alvo || blank($campanha->meta_campaign_id))) {
            throw new MetaAdsException('A campanha não está na Meta: envie a campanha inteira.');
        }

        try {
            // 1. Sobe todas as mídias primeiro: a Meta processa os vídeos em paralelo.
            foreach ($videos as $video) {
                $passo = "envio do {$this->rotuloDoAnuncio($video)}";
                if ($video->ehImagem() && blank($video->meta_image_hash)) {
                    [$hash, $url] = $this->subirImagem($video);
                    $video->forceFill(['meta_image_hash' => $hash, 'meta_thumbnail_url' => $url, 'ultimo_erro' => null])->save();

                    return self::ENVIO_CONTINUAR;
                }
                if ($video->precisaFicarVertical() && blank($video->meta_video_id) && blank($video->path_vertical)) {
                    // Horizontal (16:9) vira 9:16 com laterais desfocadas antes de subir.
                    $passo = "ajuste para 9:16 do {$this->rotuloDoAnuncio($video)}";
                    $video->forceFill(['path_vertical' => $this->deixarVertical($video), 'ultimo_erro' => null])->save();

                    return self::ENVIO_CONTINUAR;
                }
                if (! $video->ehImagem() && blank($video->meta_video_id)) {
                    $video->forceFill(['meta_video_id' => $this->subirVideo($video), 'ultimo_erro' => null])->save();

                    return self::ENVIO_CONTINUAR;
                }
            }

            // 2. Espera o processamento dos vídeos e pega a capa que a Meta escolheu.
            $todosProntos = true;
            foreach ($videos as $video) {
                $passo = "processamento do {$this->rotuloDoAnuncio($video)}";
                if ($video->midiaPronta()) {
                    continue;
                }

                $capa = $this->capaSeProcessado((string) $video->meta_video_id);
                if ($capa === null) {
                    $todosProntos = false;

                    continue;
                }

                $video->forceFill(['meta_thumbnail_url' => $capa])->save();
            }

            if (! $todosProntos) {
                return self::ENVIO_AGUARDANDO;
            }

            // 3. Criativos antes da campanha. No reenvio, anúncio que já está
            // no conjunto (criado aqui ou à mão no Gerenciador com a mesma
            // mídia) é aproveitado em vez de duplicado.
            foreach ($videos as $video) {
                if (filled($video->meta_ad_id)) {
                    continue;
                }

                $passo = "conferência do {$this->rotuloDoAnuncio($video)} na Meta";
                $adset = $video->meta_adset_id ?: $campanha->conjuntos->firstWhere('id', $video->conjunto_id)?->meta_adset_id;
                if (filled($adset) && ($existente = $this->anuncioJaNoConjunto((string) $adset, $video))) {
                    $video->forceFill([
                        'meta_adset_id' => $adset,
                        'meta_creative_id' => $existente['creative_id'],
                        'meta_ad_id' => $existente['ad_id'],
                        'ultimo_erro' => null,
                    ])->save();

                    continue;
                }

                $passo = "criativo do {$this->rotuloDoAnuncio($video)}";
                if (blank($video->meta_creative_id)) {
                    $video->forceFill(['meta_creative_id' => $this->criarCriativo($campanha, $video)])->save();
                }
            }

            $video = null;
            $passo = 'campanha';
            if (blank($campanha->meta_campaign_id)) {
                $resposta = $this->post($this->conta().'/campaigns', $this->payloadCampanha($campanha));
                $campanha->forceFill(['meta_campaign_id' => (string) $resposta['id']])->save();
            }

            // 4. Cada conjunto e, dentro dele, o anúncio de cada mídia.
            foreach ($conjuntos as $conjunto) {
                $anuncios = $videos->where('conjunto_id', $conjunto->id);
                if ($anuncios->isEmpty()) {
                    continue;
                }

                $video = null;
                $passo = "conjunto {$conjunto->nome}";
                if (blank($conjunto->meta_adset_id)) {
                    $resposta = $this->post($this->conta().'/adsets', $this->payloadConjunto($campanha, $conjunto));
                    $conjunto->forceFill(['meta_adset_id' => (string) $resposta['id'], 'ultimo_erro' => null])->save();
                }

                foreach ($anuncios as $video) {
                    if (blank($video->meta_adset_id)) {
                        $video->forceFill([
                            'meta_adset_id' => $conjunto->meta_adset_id,
                            'orcamento_diario' => $campanha->orcamentoDaCampanha() ? null : $conjunto->orcamento_diario,
                        ])->save();
                    }

                    $passo = "{$this->rotuloDoAnuncio($video)} ({$conjunto->nome})";
                    if (blank($video->meta_ad_id)) {
                        $resposta = $this->post($this->conta().'/ads', $this->payloadAnuncio($campanha, $video, $conjunto));
                        $video->forceFill(['meta_ad_id' => (string) $resposta['id'], 'ultimo_erro' => null])->save();
                    }
                }
            }
        } catch (MetaAdsException $erro) {
            $mensagem = "Falhou no passo {$passo}: {$erro->getMessage()}";
            if ($video) {
                $video->forceFill(['ultimo_erro' => mb_substr($erro->getMessage(), 0, 1000)])->save();
            } elseif (! $alvo && isset($conjunto) && str_starts_with($passo, 'conjunto ')) {
                $conjunto->forceFill(['ultimo_erro' => mb_substr($erro->getMessage(), 0, 1000)])->save();
            }

            if ($alvo) {
                $alvo->forceFill(['enviando' => false, 'ultimo_erro' => mb_substr($mensagem, 0, 1000)])->save();
            } else {
                $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ERRO, 'ultimo_erro' => $mensagem])->save();
            }

            throw $erro;
        }

        if ($alvo) {
            $alvo->forceFill(['enviando' => false, 'ultimo_erro' => null])->save();

            return self::ENVIO_PRONTO;
        }

        $campanha->forceFill([
            'status' => MetaAdsCampanha::STATUS_ENVIADA_PAUSADA,
            'ultimo_erro' => null,
            'enviada_em' => now(),
        ])->save();

        return self::ENVIO_PRONTO;
    }

    /**
     * Liga ou pausa campanha, conjuntos e anúncios na Meta. Ao ativar, liga
     * dos anúncios para a campanha; ao pausar, desliga da campanha para
     * baixo, para nunca sobrar um pedaço gastando.
     *
     * @param  'ACTIVE'|'PAUSED'  $status
     *
     * @throws MetaAdsException
     */
    public function alterarStatus(MetaAdsCampanha $campanha, string $status): void
    {
        $campanha->loadMissing(['conjuntos', 'videos']);

        // Conjunto ou anúncio novo que ainda não terminou de subir não liga
        // junto (pausar desliga tudo, inclusive ele).
        $ativando = $status === 'ACTIVE';
        $fora = $ativando ? $campanha->conjuntosNovosPendentes()->pluck('id') : collect();
        $conjuntos = $campanha->conjuntos->whereNotIn('id', $fora);
        $videos = $campanha->videos->whereNotIn('conjunto_id', $fora)
            ->reject(fn (MetaAdsVideo $v) => $ativando && $v->adicionado_depois && ! $v->envioCompleto());

        // Vários anúncios apontam para o mesmo conjunto: cada id uma vez só.
        $ids = array_values(array_unique(array_merge(
            $videos->pluck('meta_ad_id')->filter()->all(),
            $conjuntos->pluck('meta_adset_id')->filter()->all(),
            $videos->pluck('meta_adset_id')->filter()->all(),
            [$campanha->meta_campaign_id],
        )));

        if ($status === 'PAUSED') {
            $ids = array_reverse($ids);
        }

        try {
            foreach ($ids as $id) {
                $this->post((string) $id, ['status' => $status]);
            }
        } catch (MetaAdsException $erro) {
            $acao = $status === 'ACTIVE' ? 'ativar' : 'pausar';
            $campanha->forceFill(['ultimo_erro' => "Falhou ao {$acao}: {$erro->getMessage()}"])->save();

            throw $erro;
        }

        $campanha->forceFill([
            'status' => $status === 'ACTIVE' ? MetaAdsCampanha::STATUS_ATIVA : MetaAdsCampanha::STATUS_ENVIADA_PAUSADA,
            'ativada_em' => $status === 'ACTIVE' ? now() : $campanha->ativada_em,
            'ultimo_erro' => null,
        ])->save();

        MetaAdsConjunto::query()
            ->whereIn('id', $conjuntos->filter(fn (MetaAdsConjunto $c) => filled($c->meta_adset_id))->pluck('id'))
            ->update(['ativado_em' => $ativando ? now() : null]);
        MetaAdsVideo::query()
            ->whereIn('id', $videos->filter(fn (MetaAdsVideo $v) => filled($v->meta_ad_id))->pluck('id'))
            ->update(['ativado_em' => $ativando ? now() : null]);
    }

    /**
     * Liga o que foi acrescentado depois do envio, dentro de campanha que já
     * está rodando: os anúncios novos e, se o conjunto é novo, o ad set. A
     * campanha e os anúncios que já existiam não são tocados.
     *
     * @throws MetaAdsException
     */
    public function ativarConjunto(MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto): void
    {
        $campanha->loadMissing('videos');
        $anuncios = $conjunto->anunciosParaAtivar($campanha);
        $ligarConjunto = $conjunto->adicionado_depois && $conjunto->ativado_em === null;

        $ids = array_values(array_unique(array_filter([
            ...$anuncios->pluck('meta_ad_id')->all(),
            $ligarConjunto ? $conjunto->meta_adset_id : null,
        ])));

        try {
            foreach ($ids as $id) {
                $this->post((string) $id, ['status' => 'ACTIVE']);
            }
        } catch (MetaAdsException $erro) {
            $conjunto->forceFill(['ultimo_erro' => mb_substr("Falhou ao ativar: {$erro->getMessage()}", 0, 1000)])->save();

            throw $erro;
        }

        MetaAdsVideo::query()->whereIn('id', $anuncios->pluck('id'))->update(['ativado_em' => now()]);
        $conjunto->forceFill(['ativado_em' => $conjunto->ativado_em ?? now(), 'ultimo_erro' => null])->save();
    }

    // Editar e excluir anúncio que já está na Meta (2026-10-19) ---------------

    /**
     * Nome do anúncio na Meta: o escolhido no KazaKora ou o automático
     * "campanha | conjunto | anúncio N (arquivo)".
     */
    public function nomeDoAnuncio(MetaAdsCampanha $campanha, MetaAdsVideo $video, ?MetaAdsConjunto $conjunto = null): string
    {
        if (filled($video->nome)) {
            return (string) $video->nome;
        }

        $conjunto ??= $campanha->relationLoaded('conjuntos')
            ? $campanha->conjuntos->firstWhere('id', $video->conjunto_id)
            : $video->conjunto;

        return $campanha->nome.' | '.($conjunto?->nome ?? 'Conjunto').' | '.$this->rotuloDoAnuncio($video);
    }

    /**
     * Aplica na Meta a edição guardada em edicao_pendente e, só depois que
     * a Meta aceita, grava os campos no anúncio local. Texto, link, botão
     * ou arquivo novo = criativo novo (adcreatives) e o anúncio passa a
     * usar ele (POST no ad com creative); só o nome = POST no ad com name.
     *
     * edicao_pendente: {campos: array, nome: ?string, muda_criativo: bool,
     * midia: ?array (arquivo novo: tipo, path, ... e os ids que a Meta
     * devolver no caminho)}.
     *
     * Vídeo novo precisa a Meta processar antes de virar criativo: devolve
     * ENVIO_CONTINUAR/ENVIO_AGUARDANDO e o job (TrocarCriativoMetaAds)
     * chama de novo. Sem arquivo ou com imagem, resolve numa chamada só.
     *
     * Erro da Meta: descarta a edição (o anúncio fica como estava), apaga
     * o arquivo novo e relança. Com $gravarErro o erro fica no anúncio
     * (envio em segundo plano, a tela mostra).
     *
     * @return self::ENVIO_*
     *
     * @throws MetaAdsException
     */
    public function avancarEdicao(MetaAdsCampanha $campanha, MetaAdsVideo $video, bool $gravarErro = false): string
    {
        $pendente = $video->edicao_pendente;
        if (blank($pendente) || blank($video->meta_ad_id)) {
            return self::ENVIO_PRONTO;
        }

        $midia = $pendente['midia'] ?? null;
        $guardar = function () use ($video, &$pendente, &$midia) {
            $pendente['midia'] = $midia;
            $video->forceFill(['edicao_pendente' => $pendente])->save();
        };

        try {
            if ($midia) {
                $alvo = $this->versaoEditada($video, $pendente);

                if ($alvo->ehImagem()) {
                    if (blank($midia['meta_image_hash'] ?? null)) {
                        [$hash, $url] = $this->subirImagem($alvo);
                        $midia['meta_image_hash'] = $hash;
                        $midia['meta_thumbnail_url'] = $url;
                        $guardar();
                    }
                } else {
                    if ($alvo->precisaFicarVertical() && blank($midia['path_vertical'] ?? null) && blank($midia['meta_video_id'] ?? null)) {
                        $midia['path_vertical'] = $this->deixarVertical($alvo);
                        $guardar();

                        return self::ENVIO_CONTINUAR;
                    }
                    if (blank($midia['meta_video_id'] ?? null)) {
                        $midia['meta_video_id'] = $this->subirVideo($alvo);
                        $guardar();

                        return self::ENVIO_CONTINUAR;
                    }
                    if (blank($midia['meta_thumbnail_url'] ?? null)) {
                        $capa = $this->capaSeProcessado((string) $midia['meta_video_id']);
                        if ($capa === null) {
                            return self::ENVIO_AGUARDANDO;
                        }
                        $midia['meta_thumbnail_url'] = $capa;
                        $guardar();
                    }
                }
            }

            $alvo = $this->versaoEditada($video, $pendente);
            $dados = [];
            $criativo = null;

            if ($pendente['muda_criativo'] ?? false) {
                $criativo = $this->criarCriativo($campanha, $alvo);
                $dados['creative'] = json_encode(['creative_id' => $criativo]);
            }
            if (filled($pendente['nome'] ?? null)) {
                $dados['name'] = (string) $pendente['nome'];
            }
            if ($dados !== []) {
                $this->post((string) $video->meta_ad_id, $dados);
            }
        } catch (MetaAdsException $erro) {
            $this->descartarEdicao($video, $gravarErro ? 'Não deu para salvar na Meta: '.$erro->getMessage().'. Nada foi alterado.' : null);

            throw $erro;
        }

        // A Meta aceitou: agora o anúncio local muda.
        $antigos = $midia ? array_filter([$video->path, $video->path_vertical]) : [];
        $video->forceFill(
            ($pendente['campos'] ?? [])
            + (filled($pendente['nome'] ?? null) ? ['nome' => $pendente['nome']] : [])
            + ($midia ? self::camposDaMidia($midia) : [])
            + ($criativo ? ['meta_creative_id' => $criativo] : [])
            + ['edicao_pendente' => null, 'ultimo_erro' => null]
        )->save();

        if ($antigos !== []) {
            Storage::disk('local')->delete(array_values(array_diff($antigos, array_filter([$video->path, $video->path_vertical]))));
        }

        $this->esquecerEspelho($campanha);

        return self::ENVIO_PRONTO;
    }

    /** Desiste da edição pendente: apaga o arquivo novo; o anúncio fica como estava. */
    public function descartarEdicao(MetaAdsVideo $video, ?string $erro = null): void
    {
        $midia = $video->edicao_pendente['midia'] ?? null;
        if ($midia) {
            $manter = array_filter([$video->path, $video->path_vertical]);
            Storage::disk('local')->delete(array_values(array_diff(array_filter([$midia['path'] ?? null, $midia['path_vertical'] ?? null]), $manter)));
        }

        $video->forceFill(['edicao_pendente' => null] + ($erro !== null ? ['ultimo_erro' => mb_substr($erro, 0, 1000)] : []))->save();
    }

    /**
     * Excluir o anúncio na Meta: status DELETED (a doc da Meta aceita isso
     * ou DELETE no objeto; os dois são definitivos). As métricas continuam
     * consultáveis pelo id do anúncio.
     *
     * @throws MetaAdsException
     */
    public function excluirAnuncio(MetaAdsCampanha $campanha, MetaAdsVideo $video): void
    {
        $this->post((string) $video->meta_ad_id, ['status' => 'DELETED']);
        $this->esquecerEspelho($campanha);
    }

    /** @throws MetaAdsException */
    public function pausarAnuncio(MetaAdsCampanha $campanha, MetaAdsVideo $video): void
    {
        $this->post((string) $video->meta_ad_id, ['status' => 'PAUSED']);
        $video->forceFill(['ativado_em' => null])->save();
        $this->esquecerEspelho($campanha);
    }

    /**
     * O anúncio é o único ativo do conjunto na Meta? (sem ele o conjunto
     * para de entregar)
     *
     * @throws MetaAdsException
     */
    public function ultimoAtivoDoConjunto(MetaAdsVideo $video): bool
    {
        $adset = (string) $video->meta_adset_id;
        if ($adset === '') {
            return false;
        }

        $ativos = collect($this->getPaginado($adset.'/ads', ['fields' => 'id,effective_status', 'limit' => 100]))
            ->filter(fn (array $a) => ($a['effective_status'] ?? '') === 'ACTIVE')
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        return $ativos->count() === 1 && $ativos->first() === (string) $video->meta_ad_id;
    }

    // Editar e excluir campanha, conjunto e anúncio pelo id da Meta (2026-10-20) --

    /** Nível na tela => campos lidos da Meta. */
    private const CAMPOS_DO_NIVEL = [
        'campanha' => 'id,name,account_id,effective_status,daily_budget,lifetime_budget',
        'conjunto' => 'id,name,account_id,campaign_id,effective_status,daily_budget,lifetime_budget',
        'anuncio' => 'id,name,account_id,campaign_id,adset_id,effective_status',
    ];

    /**
     * Lê campanha, conjunto ou anúncio na Meta e confere que é da conta de
     * anúncios da loja (o id vem da tela; nada fora da conta é tocado).
     *
     * @param  'campanha'|'conjunto'|'anuncio'  $nivel
     * @return array{id: string, nome: string, status: string, campanha_id: ?string, conjunto_id: ?string, orcamento_diario: ?float, orcamento_total: ?float}
     *
     * @throws MetaAdsException
     */
    public function objeto(string $nivel, string $id): array
    {
        $dados = $this->get($id, ['fields' => self::CAMPOS_DO_NIVEL[$nivel]]);

        if ((string) ($dados['account_id'] ?? '') !== preg_replace('/^act_/', '', $this->conta())) {
            throw new MetaAdsException('Este item não é da conta de anúncios da loja.');
        }

        return [
            'id' => (string) $dados['id'],
            'nome' => (string) ($dados['name'] ?? ''),
            'status' => (string) ($dados['effective_status'] ?? ''),
            'campanha_id' => $nivel === 'campanha' ? (string) $dados['id'] : (isset($dados['campaign_id']) ? (string) $dados['campaign_id'] : null),
            'conjunto_id' => isset($dados['adset_id']) ? (string) $dados['adset_id'] : null,
            'orcamento_diario' => $this->centavosEmReais($dados['daily_budget'] ?? null),
            'orcamento_total' => $this->centavosEmReais($dados['lifetime_budget'] ?? null),
        ];
    }

    /**
     * Nome e, quando o orçamento é diário neste nível, o orçamento por dia.
     *
     * @param  array{id: string, campanha_id: ?string}  $objeto  (ver objeto())
     *
     * @throws MetaAdsException
     */
    public function editarObjeto(array $objeto, string $nome, ?float $orcamentoDiario): void
    {
        $dados = ['name' => $nome];
        if ($orcamentoDiario !== null) {
            $dados['daily_budget'] = (int) round($orcamentoDiario * 100);
        }

        $this->post($objeto['id'], $dados);
        $this->esquecerEspelho($objeto['campanha_id']);
    }

    /**
     * Excluir na Meta é status DELETED (definitivo). Na campanha e no
     * conjunto a Meta exclui junto o que está dentro.
     *
     * @param  array{id: string, campanha_id: ?string}  $objeto
     *
     * @throws MetaAdsException
     */
    public function excluirObjeto(array $objeto): void
    {
        $this->post($objeto['id'], ['status' => 'DELETED']);
        $this->esquecerEspelho($objeto['campanha_id']);
    }

    /**
     * Pausa na Meta pelo id (botão "Pausar criativo" das sugestões, Lira
     * 2026-10-21). Volta pelo Gerenciador ou pelo Ativar do KazaKora.
     *
     * @param  array{id: string, campanha_id: ?string}  $objeto
     *
     * @throws MetaAdsException
     */
    public function pausarObjeto(array $objeto): void
    {
        $this->post($objeto['id'], ['status' => 'PAUSED']);
        $this->esquecerEspelho($objeto['campanha_id']);
        $this->esquecerStatusAoVivo();
    }

    /**
     * É o único conjunto ativo da campanha, ou o único anúncio ativo do
     * conjunto? (sem ele, a campanha ou o conjunto para de entregar)
     *
     * @param  'conjunto'|'anuncio'  $nivel
     * @param  array{id: string, status: string, campanha_id: ?string, conjunto_id: ?string}  $objeto
     *
     * @throws MetaAdsException
     */
    public function unicoAtivo(string $nivel, array $objeto): bool
    {
        $pai = $nivel === 'conjunto' ? $objeto['campanha_id'] : $objeto['conjunto_id'];
        if (blank($pai) || $objeto['status'] !== 'ACTIVE') {
            return false;
        }

        $ativos = collect($this->getPaginado($pai.($nivel === 'conjunto' ? '/adsets' : '/ads'), ['fields' => 'id,effective_status', 'limit' => 100]))
            ->filter(fn (array $item) => ($item['effective_status'] ?? '') === 'ACTIVE')
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        return $ativos->count() === 1 && $ativos->first() === $objeto['id'];
    }

    // Status ao vivo e troca de link (pedido do Lira 2026-10-21) --------------

    /**
     * effective_status de todas as campanhas, conjuntos e anúncios da conta,
     * por id. Três leituras leves (só id e status), guardadas 15 segundos:
     * a tela pergunta a cada 20 s sem pesar na cota da Meta.
     *
     * @return array{campanhas: array<string, string>, conjuntos: array<string, string>, anuncios: array<string, string>, lido_em: string}
     *
     * @throws MetaAdsException
     */
    public function statusAoVivo(): array
    {
        return Cache::remember('meta_ads:status_ao_vivo:'.md5($this->conta()), 15, function () {
            $ler = fn (string $borda) => collect($this->getPaginado($this->conta().'/'.$borda, ['fields' => 'id,effective_status', 'limit' => 500]))
                ->mapWithKeys(fn (array $item) => [(string) $item['id'] => (string) ($item['effective_status'] ?? '')])
                ->all();

            return [
                'campanhas' => $ler('campaigns'),
                'conjuntos' => $ler('adsets'),
                'anuncios' => $ler('ads'),
                'lido_em' => now(self::FUSO)->toIso8601String(),
            ];
        });
    }

    public function esquecerStatusAoVivo(): void
    {
        Cache::forget('meta_ads:status_ao_vivo:'.md5($this->conta()));
    }

    /**
     * Dá para trocar o link pelo admin? Só criativo com object_story_spec
     * (imagem ou vídeo com link). Catálogo (product_set_id) e anúncio feito
     * de publicação existente ficam no Gerenciador.
     *
     * @param  array<string, mixed>  $criativo
     */
    public static function linkTrocavel(array $criativo): bool
    {
        $spec = $criativo['object_story_spec'] ?? null;

        return is_array($spec) && blank($criativo['product_set_id'] ?? null)
            && (isset($spec['link_data']) || isset($spec['video_data']['call_to_action']));
    }

    /**
     * Criativo da Meta com o link trocado em todo lugar onde ele aparece
     * (link_data.link, botão e link_urls do asset_feed_spec), pronto para
     * POST em adcreatives. Os campos que a Meta devolve na leitura mas
     * recusa na criação saem (imagem por hash e por url juntas).
     *
     * @param  array<string, mixed>  $criativo  lido com object_story_spec, asset_feed_spec, url_tags e name
     * @return array<string, string>
     */
    public static function criativoComLinkNovo(array $criativo, string $link): array
    {
        $spec = $criativo['object_story_spec'];
        foreach (['link_data', 'video_data'] as $chave) {
            if (! isset($spec[$chave])) {
                continue;
            }
            if ($chave === 'link_data') {
                $spec[$chave]['link'] = $link;
                if (filled($spec[$chave]['image_hash'] ?? null)) {
                    unset($spec[$chave]['picture']);
                }
            }
            if ($chave === 'video_data' && filled($spec[$chave]['image_hash'] ?? null)) {
                unset($spec[$chave]['image_url']);
            }
            if (isset($spec[$chave]['call_to_action'])) {
                $spec[$chave]['call_to_action']['value']['link'] = $link;
            }
        }

        $payload = [
            'name' => mb_substr(((string) ($criativo['name'] ?? 'Criativo')).' | link novo', 0, 255),
            'object_story_spec' => json_encode($spec),
        ];

        if (! empty($criativo['asset_feed_spec'])) {
            $feed = $criativo['asset_feed_spec'];
            if (isset($feed['link_urls'])) {
                $feed['link_urls'] = array_map(fn (array $l) => array_merge($l, ['website_url' => $link]), $feed['link_urls']);
            }
            $payload['asset_feed_spec'] = json_encode($feed);
        }

        if (filled($criativo['url_tags'] ?? null)) {
            $payload['url_tags'] = (string) $criativo['url_tags'];
        }

        return $payload;
    }

    /**
     * Troca o link de um anúncio feito no Gerenciador: criativo novo igual
     * ao atual com o link novo, e o anúncio passa a usar ele (volta para a
     * análise da Meta). Se a Meta recusar o criativo, nada muda no anúncio.
     *
     * @param  array{id: string, campanha_id: ?string}  $objeto  (ver objeto('anuncio', ...))
     *
     * @throws MetaAdsException
     */
    public function trocarLinkDoAnuncio(array $objeto, string $link): string
    {
        $ad = $this->get($objeto['id'], ['fields' => 'creative{id,name,object_story_spec,asset_feed_spec,url_tags,product_set_id}']);
        $criativo = $ad['creative'] ?? [];

        if (! self::linkTrocavel($criativo)) {
            throw new MetaAdsException('Este anúncio não tem link próprio (catálogo ou publicação existente). Troque pelo Gerenciador');
        }

        $novo = (string) $this->post($this->conta().'/adcreatives', self::criativoComLinkNovo($criativo, $link))['id'];
        $this->post($objeto['id'], ['creative' => json_encode(['creative_id' => $novo])]);
        $this->esquecerEspelho($objeto['campanha_id']);
        $this->esquecerStatusAoVivo();

        return $novo;
    }

    /** O espelho fica guardado 2 minutos: depois de mudar algo, lê a Meta de novo. */
    public function esquecerEspelho(MetaAdsCampanha|string|null $campanha = null): void
    {
        $campanhaId = $campanha instanceof MetaAdsCampanha ? $campanha->meta_campaign_id : $campanha;

        foreach (array_keys(self::PERIODOS) as $periodo) {
            foreach (array_unique([null, $campanhaId]) as $id) {
                Cache::forget(self::CHAVE_ESPELHO.md5($this->conta().'|'.$periodo.'|'.$id));
            }
        }
    }

    /** Cópia do anúncio (sem gravar) com os campos e o arquivo da edição. */
    private function versaoEditada(MetaAdsVideo $video, array $pendente): MetaAdsVideo
    {
        $alvo = $video->replicate(['edicao_pendente']);
        $alvo->forceFill(($pendente['campos'] ?? []) + (($pendente['midia'] ?? null) ? self::camposDaMidia($pendente['midia']) : []));

        return $alvo;
    }

    /** @return array<string, mixed> */
    private static function camposDaMidia(array $midia): array
    {
        return [
            'tipo' => $midia['tipo'],
            'path' => $midia['path'],
            'nome_original' => $midia['nome_original'],
            'tamanho' => $midia['tamanho'],
            'duracao_segundos' => $midia['duracao_segundos'] ?? null,
            'largura' => $midia['largura'] ?? null,
            'altura' => $midia['altura'] ?? null,
            'path_vertical' => $midia['path_vertical'] ?? null,
            'meta_video_id' => $midia['meta_video_id'] ?? null,
            'meta_image_hash' => $midia['meta_image_hash'] ?? null,
            'meta_thumbnail_url' => $midia['meta_thumbnail_url'] ?? null,
        ];
    }

    /**
     * O que impede o envio do conjunto novo (lista vazia = pode enviar).
     *
     * @return list<string>
     */
    public function problemasDoConjuntoNovo(MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto): array
    {
        $problemas = [];
        $real = fn (float $valor) => number_format($valor, 2, ',', '.');

        if (! $this->configurado()) {
            $problemas[] = 'Meta não configurado.';
        }

        if ($campanha->objetivo === 'OUTCOME_SALES' && ! $this->pixelConfigurado()) {
            $problemas[] = 'Objetivo Vendas precisa do pixel da Meta (Configurações › Pixel da Meta).';
        }

        $campanha->loadMissing('videos');
        $anuncios = $conjunto->anunciosNovosPendentes($campanha);

        if ($campanha->videos->where('conjunto_id', $conjunto->id)->isEmpty()) {
            $problemas[] = "O conjunto {$conjunto->nome} não tem anúncio. Adicione um vídeo ou imagem.";
        }

        if ($conjunto->adicionado_depois && blank($conjunto->meta_adset_id) && ! $campanha->orcamentoDaCampanha() && (float) $conjunto->orcamento_diario < MetaAdsCampanha::MINIMO_POR_CONJUNTO) {
            $problemas[] = "Orçamento baixo no conjunto {$conjunto->nome}: o mínimo é R$ {$real(MetaAdsCampanha::MINIMO_POR_CONJUNTO)} por dia.";
        }

        foreach ($anuncios as $video) {
            $nome = $this->rotuloDoAnuncio($video);
            $criativo = $video->criativo($campanha);

            if (blank($video->ehImagem() ? $video->meta_image_hash : $video->meta_video_id) && ! Storage::disk('local')->exists($video->path)) {
                $problemas[] = "O arquivo do {$nome} sumiu do servidor. Tire e envie de novo.";
            }
            if (blank($criativo['texto'])) {
                $problemas[] = "Falta o texto principal do {$nome} (ou um texto padrão na campanha).";
            }
            if (blank($criativo['titulo'])) {
                $problemas[] = "Falta o título do {$nome} (ou um título padrão na campanha).";
            }
        }

        return $problemas;
    }

    /**
     * Resultado de cada anúncio (um por vídeo) desde o começo da campanha.
     *
     * @return array<string, array{gasto: float, impressoes: int, cliques: int, compras: int, vendas: float}>
     *
     * @throws MetaAdsException
     */
    public function desempenhoPorAnuncio(MetaAdsCampanha $campanha): array
    {
        if (blank($campanha->meta_campaign_id)) {
            return [];
        }

        $linhas = $this->getPaginado($campanha->meta_campaign_id.'/insights', [
            'level' => 'ad',
            'date_preset' => 'maximum',
            'fields' => 'ad_id,spend,impressions,clicks,actions,action_values',
            'limit' => 100,
        ]);

        $resultado = [];
        foreach ($linhas as $linha) {
            $resultado[(string) $linha['ad_id']] = [
                'gasto' => round((float) ($linha['spend'] ?? 0), 2),
                'impressoes' => (int) ($linha['impressions'] ?? 0),
                'cliques' => (int) ($linha['clicks'] ?? 0),
                'compras' => (int) round($this->valorDeCompra($linha['actions'] ?? [])),
                'vendas' => round($this->valorDeCompra($linha['action_values'] ?? []), 2),
            ];
        }

        return $resultado;
    }

    /**
     * Gasto e resultados da conta inteira num período (Lira 2026-10-27: a
     * tela Gasto e resultados ficava vazia sem channel_ad_spends). O nível
     * account soma tudo, inclusive campanhas já excluídas. Só leitura.
     *
     * @return array{gasto: float, impressoes: int, cliques: int, pedidos: int, vendas: float, roas: float|null}
     *
     * @throws MetaAdsException
     */
    public function totaisDaConta(string $datePreset): array
    {
        $linha = Cache::remember('meta-ads:totais-conta:'.md5($this->conta().'|'.$datePreset), now()->addMinutes(5),
            fn () => $this->get($this->conta().'/insights', [
                'level' => 'account',
                'date_preset' => $datePreset,
                'fields' => 'spend,impressions,clicks,actions,action_values',
            ])['data'][0] ?? []);
        $m = $this->metricas($linha);

        return [
            'gasto' => $m['gasto'],
            'impressoes' => $m['impressoes'],
            'cliques' => $m['cliques'],
            'pedidos' => $m['compras'],
            'vendas' => $m['vendas'],
            'roas' => $m['roas'],
        ];
    }

    /** Períodos da tela (date_preset da Meta => rótulo). */
    public const PERIODOS = [
        'today' => 'Hoje',
        'yesterday' => 'Ontem',
        'last_7d' => 'Últimos 7 dias',
        'last_30d' => 'Últimos 30 dias',
        // Central de campanhas (2026-10-23): o mês corrente, como no Gerenciador.
        'this_month' => 'Mês atual',
        'maximum' => 'Desde o início',
    ];

    /**
     * Filtro de status da tela (pedido do Lira 2026-10-21): chave => [rótulo,
     * effective_status da Meta que entram]. "Inativos" é tudo o que não está
     * ACTIVE; "Todos" não filtra. Abre em "Ativos".
     */
    public const FILTROS_STATUS = [
        'ativos' => ['Ativos', ['ACTIVE']],
        'pausados' => ['Pausados', ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED']],
        'em_analise' => ['Em análise', ['IN_PROCESS', 'PENDING_REVIEW', 'PREAPPROVED']],
        'com_problema' => ['Com problema ou reprovados', ['WITH_ISSUES', 'DISAPPROVED', 'PENDING_BILLING_INFO']],
        'arquivados' => ['Arquivados', ['ARCHIVED']],
        'excluidos' => ['Excluídos', ['DELETED']],
        'inativos' => ['Todos os inativos', []],
        'todos' => ['Todos', []],
    ];

    public const FILTRO_STATUS_PADRAO = 'ativos';

    /**
     * effective_status que as bordas /campaigns, /adsets e /ads aceitam
     * listar. Sem o parâmetro a Meta esconde os arquivados; DELETED ela
     * recusa (erro 1815001), excluído só sai pelo insights.
     */
    private const STATUS_LISTAVEIS = ['ACTIVE', 'PAUSED', 'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO',
        'CAMPAIGN_PAUSED', 'ARCHIVED', 'ADSET_PAUSED', 'IN_PROCESS', 'WITH_ISSUES'];

    /** O filtro precisa ler arquivados e excluídos, que a Meta não traz por padrão. */
    public static function filtroLeArquivados(string $filtro): bool
    {
        return in_array($filtro, ['arquivados', 'excluidos', 'inativos', 'todos'], true);
    }

    public static function statusEntra(string $status, string $filtro): bool
    {
        return match ($filtro) {
            'todos' => true,
            'inativos' => $status !== 'ACTIVE',
            default => in_array($status, self::FILTROS_STATUS[$filtro][1] ?? [], true),
        };
    }

    /**
     * Espelho só com o que tem o status do filtro. O item fica se ele tem o
     * status ou se tem filho com o status (anúncio excluído aparece dentro
     * da campanha e do conjunto ativos). Os totais de dentro continuam
     * contando tudo (confirmação de excluir a campanha).
     *
     * @param  list<array<string, mixed>>  $campanhas
     * @return list<array<string, mixed>>
     */
    public static function filtrarPorStatus(array $campanhas, string $filtro): array
    {
        if ($filtro === 'todos') {
            return $campanhas;
        }

        $entra = fn (array $item) => self::statusEntra((string) ($item['status'] ?? ''), $filtro);
        $saida = [];

        foreach ($campanhas as $c) {
            $conjuntos = [];
            foreach ($c['conjuntos'] ?? [] as $s) {
                $anuncios = array_values(array_filter($s['anuncios'] ?? [], $entra));
                if ($entra($s) || $anuncios !== []) {
                    $conjuntos[] = ['anuncios' => $anuncios, 'anuncios_total' => count($s['anuncios'] ?? [])] + $s;
                }
            }

            if ($entra($c) || $conjuntos !== []) {
                $saida[] = [
                    'conjuntos' => $conjuntos,
                    'conjuntos_total' => count($c['conjuntos'] ?? []),
                    'anuncios_total' => array_sum(array_map(fn ($s) => count($s['anuncios'] ?? []), $c['conjuntos'] ?? [])),
                ] + $c;
            }
        }

        return $saida;
    }

    /**
     * Espelho do Gerenciador (pedido do Lira 2026-10-18): a estrutura real
     * da conta, Campanha > Conjuntos > Anúncios, lida AO VIVO da Meta, com
     * as métricas de cada nível. Anúncio criado direto no Gerenciador
     * aparece também (a Árvore de Natal tinha 3 criativos e o banco só
     * conhecia o 1º). Cada nível tem a própria consulta de insights porque
     * alcance não se soma (a mesma pessoa vê vários anúncios).
     * Guardado 2 minutos para não bater na Meta a cada clique.
     *
     * @return list<array<string, mixed>>
     *
     * @throws MetaAdsException
     */
    public function espelho(string $periodo = 'maximum', ?string $campaignId = null): array
    {
        return $this->espelhoCompleto($periodo, $campaignId)['campanhas'];
    }

    /**
     * O espelho com o que o "O que fazer agora" (2026-10-21) precisa além
     * das campanhas: a média da conta (CPC do clique no link), a hora em que
     * a Meta foi lida e os campos extras que a Meta recusou (a regra que
     * depende deles fica de fora em vez de quebrar a tela).
     *
     * @return array{campanhas: list<array<string, mixed>>, conta: ?array<string, mixed>, gerado_em: string, faltando: list<string>}
     *
     * @throws MetaAdsException
     */
    public function espelhoCompleto(string $periodo = 'maximum', ?string $campaignId = null, bool $comArquivados = false): array
    {
        $periodo = array_key_exists($periodo, self::PERIODOS) ? $periodo : 'maximum';

        return Cache::remember(
            self::CHAVE_ESPELHO.md5($this->conta().'|'.$periodo.'|'.$campaignId.($comArquivados ? '|arquivados' : '')),
            now()->addMinutes(2),
            fn () => $this->montarEspelho($periodo, $campaignId, $comArquivados),
        );
    }

    /**
     * "O que fazer agora" (2026-10-21) do espelho já lido: liga o espelho,
     * a média da conta e o ROAS mínimo de cada campanha às regras puras de
     * MetaAdsDiagnostico. Só leitura.
     *
     * @param  array{campanhas: list<array<string, mixed>>, conta: ?array<string, mixed>, gerado_em: string}  $completo
     * @return array<string, mixed>
     */
    public function diagnosticar(array $completo, string $periodo): array
    {
        $ids = array_map(fn ($c) => (string) $c['id'], $completo['campanhas']);

        return (new MetaAdsDiagnostico((array) config('meta_ads_diagnostico', [])))->analisar($completo['campanhas'], [
            'periodo' => $periodo,
            'gerado_em' => $completo['gerado_em'] ?? null,
            'cpc_conta' => $completo['conta']['cpc_link'] ?? null,
            'roas' => app(MetaRoasMinimo::class)->metas($ids),
        ]) + ['faltando' => $completo['faltando'] ?? []];
    }

    private const CHAVE_ESPELHO = 'meta-ads:espelho-v2:';

    /** Campos de insights de cada nível (os extras alimentam o diagnóstico). */
    private const INSIGHTS_BASE = 'spend,impressions,reach,clicks,actions,action_values';

    private const INSIGHTS_EXTRAS = ',frequency,inline_link_clicks,inline_link_click_ctr,cpm';

    /** @return array{campanhas: list<array<string, mixed>>, conta: ?array<string, mixed>, gerado_em: string, faltando: list<string>} */
    private function montarEspelho(string $periodo, ?string $campaignId, bool $comArquivados = false): array
    {
        $daCampanha = $campaignId ? [['field' => 'campaign.id', 'operator' => 'EQUAL', 'value' => $campaignId]] : [];
        $filtro = $daCampanha ? ['filtering' => json_encode($daCampanha)] : [];
        // Filtro de status (2026-10-21): arquivados só vêm pedindo o effective_status.
        $listar = $comArquivados ? ['effective_status' => json_encode(self::STATUS_LISTAVEIS)] : [];
        $faltando = [];

        $camposCampanha = 'id,name,effective_status,objective,daily_budget,lifetime_budget,start_time,stop_time';
        $campanhas = $campaignId
            ? [$this->get($campaignId, ['fields' => $camposCampanha])]
            : $this->getPaginado($this->conta().'/campaigns', $listar + ['fields' => $camposCampanha, 'limit' => 100]);
        $conjuntos = $this->getPaginadoComExtras($this->conta().'/adsets', $filtro + $listar + ['limit' => 200],
            'id,name,campaign_id,effective_status,daily_budget,lifetime_budget', ',learning_stage_info', 'aprendizado', $faltando);
        // Botão do anúncio (2026-10-21): no criativo simples vem em
        // call_to_action_type / object_story_spec; no dinâmico, em
        // asset_feed_spec.call_to_action_types (pode ter vários).
        $anuncios = $this->getPaginadoComExtras($this->conta().'/ads', $filtro + $listar + ['limit' => 200],
            'id,name,adset_id,campaign_id,effective_status,creative{thumbnail_url}',
            ',created_time,creative{thumbnail_url,call_to_action_type,object_story_spec,asset_feed_spec{call_to_action_types,link_urls},product_set_id}',
            'botao', $faltando, substituir: true);

        $metricas = [];
        foreach (['campaign', 'adset', 'ad'] as $nivel) {
            // Com o filtro de status, o insights traz também o que foi excluído.
            $filtragem = array_merge($daCampanha, $comArquivados
                ? [['field' => $nivel.'.effective_status', 'operator' => 'IN', 'value' => [...self::STATUS_LISTAVEIS, 'DELETED']]]
                : []);
            $linhas = $this->getPaginadoComExtras($this->conta().'/insights', ($filtragem ? ['filtering' => json_encode($filtragem)] : []) + [
                'level' => $nivel,
                'date_preset' => $periodo,
                'limit' => 500,
            ], $nivel.'_id,'.self::INSIGHTS_BASE, self::INSIGHTS_EXTRAS, 'insights_extras', $faltando);
            foreach ($linhas as $linha) {
                $metricas[$nivel][(string) $linha[$nivel.'_id']] = $this->metricas($linha);
            }
        }

        // Excluído a Meta não lista: o que teve gasto no período e não veio
        // nas listas é lido um a um pelo id (até 50 por nível).
        if ($comArquivados) {
            $campanhas = array_merge($campanhas, $this->lerForaDaLista(array_keys($metricas['campaign'] ?? []), $campanhas, $camposCampanha));
            $conjuntos = array_merge($conjuntos, $this->lerForaDaLista(array_keys($metricas['adset'] ?? []), $conjuntos,
                'id,name,campaign_id,effective_status,daily_budget,lifetime_budget'.(in_array('aprendizado', $faltando, true) ? '' : ',learning_stage_info')));
            $anuncios = array_merge($anuncios, $this->lerForaDaLista(array_keys($metricas['ad'] ?? []), $anuncios,
                in_array('botao', $faltando, true)
                    ? 'id,name,adset_id,campaign_id,effective_status,creative{thumbnail_url}'
                    : 'id,name,adset_id,campaign_id,effective_status,created_time,creative{thumbnail_url,call_to_action_type,object_story_spec,asset_feed_spec{call_to_action_types,link_urls},product_set_id}'));
        }

        // Média da conta inteira (CPC do clique no link, regra R4), mesmo
        // na tela de uma campanha só. Se a Meta recusar, a regra usa o teto fixo.
        try {
            $conta = $this->get($this->conta().'/insights', [
                'level' => 'account',
                'date_preset' => $periodo,
                'fields' => 'spend,impressions,inline_link_clicks',
            ])['data'][0] ?? null;
            $conta = $conta ? $this->metricas($conta) : null;
        } catch (MetaAdsException) {
            $conta = null;
            $faltando[] = 'media_da_conta';
        }

        $objetivoDe = collect($campanhas)->mapWithKeys(fn ($c) => [(string) $c['id'] => (string) ($c['objective'] ?? '')]);
        $anunciosPorConjunto = collect($anuncios)->groupBy(fn ($a) => (string) $a['adset_id']);

        $conjuntosPorCampanha = collect($conjuntos)->groupBy(fn ($c) => (string) $c['campaign_id'])
            ->map(fn ($lista) => $lista->map(fn (array $c) => [
                'id' => (string) $c['id'],
                'nome' => (string) $c['name'],
                'status' => (string) ($c['effective_status'] ?? ''),
                'orcamento_diario' => $this->centavosEmReais($c['daily_budget'] ?? null),
                'orcamento_total' => $this->centavosEmReais($c['lifetime_budget'] ?? null),
                // LEARNING, SUCCESS ou FAIL; null quando a Meta não informa.
                'aprendizado' => isset($c['learning_stage_info']['status']) ? (string) $c['learning_stage_info']['status'] : null,
                'gerenciador_conjunto' => $this->linkConjuntoGerenciador((string) $c['id']),
                'metricas' => $metricas['adset'][(string) $c['id']] ?? $this->metricas([]),
                'anuncios' => $anunciosPorConjunto->get((string) $c['id'], collect())->map(fn (array $a) => [
                    'id' => (string) $a['id'],
                    'nome' => (string) $a['name'],
                    'status' => (string) ($a['effective_status'] ?? ''),
                    'miniatura' => $a['creative']['thumbnail_url'] ?? null,
                    'gerenciador' => $this->linkAnuncioGerenciador((string) $a['id']),
                    'criado_em' => isset($a['created_time']) ? Carbon::parse($a['created_time'])->setTimezone(self::FUSO)->toIso8601String() : null,
                    'cta' => in_array('botao', $faltando, true) ? null
                        : self::ctaDoCriativo($a['creative'] ?? null, $objetivoDe->get((string) $a['campaign_id'], '')),
                    // Link de destino e se dá para trocar pelo admin (2026-10-21).
                    'link' => in_array('botao', $faltando, true) ? null : self::textoDoCriativo($a['creative'] ?? [])['link'],
                    'link_editavel' => ! in_array('botao', $faltando, true) && self::linkTrocavel($a['creative'] ?? []),
                    'metricas' => $metricas['ad'][(string) $a['id']] ?? $this->metricas([]),
                ])->sortBy('nome', SORT_NATURAL)->values()->all(),
            ])->sortBy('nome', SORT_NATURAL)->values()->all());

        $lista = collect($campanhas)
            ->map(function (array $c) use ($conjuntosPorCampanha, $metricas) {
                $sets = $conjuntosPorCampanha->get((string) $c['id'], []);
                $daCampanha = $this->centavosEmReais($c['daily_budget'] ?? null);

                return [
                    'id' => (string) $c['id'],
                    'nome' => (string) $c['name'],
                    'status' => (string) ($c['effective_status'] ?? ''),
                    'gerenciador' => $this->linkCampanhaGerenciador((string) $c['id']),
                    'objetivo' => MetaAdsCampanha::OBJETIVOS[$c['objective'] ?? ''] ?? (string) ($c['objective'] ?? ''),
                    'objetivo_codigo' => (string) ($c['objective'] ?? ''),
                    'orcamento_nivel' => $daCampanha !== null ? 'campanha' : 'conjunto',
                    // Orçamento por conjunto: o diário da campanha é a soma dos conjuntos ativos.
                    'orcamento_diario' => $daCampanha ?? round(collect($sets)
                        ->filter(fn ($s) => $s['status'] === 'ACTIVE')
                        ->sum(fn ($s) => (float) $s['orcamento_diario']), 2),
                    'inicio' => isset($c['start_time']) ? Carbon::parse($c['start_time'])->setTimezone(self::FUSO)->toDateString() : null,
                    'fim' => isset($c['stop_time']) ? Carbon::parse($c['stop_time'])->setTimezone(self::FUSO)->toDateString() : null,
                    'metricas' => $metricas['campaign'][(string) $c['id']] ?? $this->metricas([]),
                    'conjuntos' => $sets,
                ];
            })
            ->sortBy(fn ($c) => [$c['status'] === 'ACTIVE' ? 0 : 1, -$c['metricas']['gasto']])
            ->values()
            ->all();

        return [
            'campanhas' => $lista,
            'conta' => $conta,
            'gerado_em' => now(self::FUSO)->toIso8601String(),
            'faltando' => array_values(array_unique($faltando)),
        ];
    }

    /**
     * Objetos com métricas no período que não vieram na lista (excluídos),
     * lidos um a um. O que a Meta não deixar ler fica de fora.
     *
     * @param  list<string|int>  $comMetricas
     * @param  list<array<string, mixed>>  $lidos
     * @return list<array<string, mixed>>
     */
    private function lerForaDaLista(array $comMetricas, array $lidos, string $campos): array
    {
        $jaLidos = array_map(fn (array $x) => (string) $x['id'], $lidos);
        $faltam = array_slice(array_values(array_diff(array_map('strval', $comMetricas), $jaLidos)), 0, 50);
        $achados = [];

        foreach ($faltam as $id) {
            try {
                $achados[] = $this->get($id, ['fields' => $campos]);
            } catch (MetaAdsException) {
                // Sem acesso ao objeto: segue sem ele.
            }
        }

        return $achados;
    }

    /**
     * Lê com os campos extras; se a Meta recusar (campo que não existe na
     * versão da API, permissão), lê de novo só com os básicos e anota o que
     * ficou de fora. Com $substituir, os extras trocam os básicos em vez de
     * somar (o criativo é uma expansão só).
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $faltando
     * @return list<array<string, mixed>>
     *
     * @throws MetaAdsException
     */
    private function getPaginadoComExtras(string $caminho, array $params, string $base, string $extras, string $nome, array &$faltando, bool $substituir = false): array
    {
        if (! in_array($nome, $faltando, true)) {
            $completo = $substituir ? preg_replace('/,?creative\{thumbnail_url\}/', '', $base).$extras : $base.$extras;
            try {
                return $this->getPaginado($caminho, $params + ['fields' => $completo]);
            } catch (MetaAdsException) {
                $faltando[] = $nome;
            }
        }

        return $this->getPaginado($caminho, $params + ['fields' => $base]);
    }

    /**
     * Botão (CTA) do criativo, como o Gerenciador mostra. Dinâmico (vários
     * botões no asset_feed_spec ou catálogo): a Meta escolhe na hora.
     * null = criativo não veio (sem dado); codigos vazio = sem botão.
     *
     * @param  array<string, mixed>|null  $criativo
     * @return array{codigos: list<string>, rotulos: array<string, ?string>, dinamico: bool, grupo: ?string, sem_botao: bool, desalinhado: bool}|null
     */
    public static function ctaDoCriativo(?array $criativo, string $objetivo = ''): ?array
    {
        if ($criativo === null) {
            return null;
        }

        $spec = $criativo['object_story_spec'] ?? [];
        $dados = $spec['video_data'] ?? $spec['link_data'] ?? $spec['template_data'] ?? [];
        $doFeed = array_values(array_filter((array) ($criativo['asset_feed_spec']['call_to_action_types'] ?? []), 'is_string'));
        $codigos = $doFeed !== [] ? $doFeed : array_values(array_filter([$criativo['call_to_action_type'] ?? $dados['call_to_action']['type'] ?? null], 'is_string'));
        $codigos = array_values(array_unique(array_map('strtoupper', $codigos)));
        $semBotao = $codigos === [] || $codigos === ['NO_BUTTON'];

        $grupos = array_map(fn ($c) => MetaAdsCampanha::GRUPOS_CHAMADA[$c] ?? null, $codigos);
        $grupo = match (true) {
            $semBotao => 'pouca_intencao',
            in_array('compra', $grupos, true) => 'compra',
            default => $grupos[0] ?? null,
        };

        return [
            'codigos' => $codigos,
            'rotulos' => array_combine($codigos, array_map(fn ($c) => MetaAdsCampanha::ROTULOS_CHAMADA[$c] ?? null, $codigos)) ?: [],
            'dinamico' => $doFeed !== [] || filled($criativo['product_set_id'] ?? null) || isset($spec['template_data']),
            'grupo' => $grupo,
            'sem_botao' => $semBotao,
            // Campanha de Vendas com botão de pouca intenção de compra (regra R5).
            'desalinhado' => $objetivo === 'OUTCOME_SALES' && in_array($grupo, ['pouca_intencao', 'conversa'], true),
        ];
    }

    /** Ações do funil, do tipo mais completo para o mais restrito. */
    private const ACOES_PAGINA = ['omni_landing_page_view', 'landing_page_view'];

    private const ACOES_CARRINHO = ['omni_add_to_cart', 'add_to_cart', 'offsite_conversion.fb_pixel_add_to_cart'];

    private const ACOES_CHECKOUT = ['omni_initiated_checkout', 'initiate_checkout', 'offsite_conversion.fb_pixel_initiate_checkout'];

    /**
     * Métricas de uma linha de insights. CPC = gasto ÷ cliques (todos os
     * cliques, a coluna "CPC (todos)" do Gerenciador); ROAS = valor das
     * compras ÷ gasto. O diagnóstico usa o clique NO LINK (cliques_link,
     * ctr_link, cpc_link); null quando a Meta não mandou o campo.
     *
     * @param  array<string, mixed>  $linha
     * @return array<string, mixed>
     */
    private function metricas(array $linha): array
    {
        $gasto = round((float) ($linha['spend'] ?? 0), 2);
        $impressoes = (int) ($linha['impressions'] ?? 0);
        $cliques = (int) ($linha['clicks'] ?? 0);
        $vendas = round($this->valorDeCompra($linha['action_values'] ?? []), 2);
        $cliquesLink = isset($linha['inline_link_clicks']) ? (int) $linha['inline_link_clicks'] : null;
        $acoes = $linha['actions'] ?? [];

        return [
            'gasto' => $gasto,
            'impressoes' => $impressoes,
            'alcance' => (int) ($linha['reach'] ?? 0),
            'cliques' => $cliques,
            'cpc' => $cliques > 0 ? round($gasto / $cliques, 2) : null,
            'compras' => (int) round($this->valorDeCompra($acoes)),
            'vendas' => $vendas,
            'roas' => $gasto > 0 ? round($vendas / $gasto, 2) : null,
            'frequencia' => isset($linha['frequency']) ? round((float) $linha['frequency'], 2) : null,
            'cpm' => isset($linha['cpm']) ? round((float) $linha['cpm'], 2) : null,
            'cliques_link' => $cliquesLink,
            'ctr_link' => isset($linha['inline_link_click_ctr']) ? round((float) $linha['inline_link_click_ctr'], 2)
                : ($cliquesLink !== null && $impressoes > 0 ? round($cliquesLink / $impressoes * 100, 2) : null),
            'cpc_link' => $cliquesLink ? round($gasto / $cliquesLink, 2) : null,
            'visualizacoes_pagina' => (int) round($this->valorDaAcao($acoes, self::ACOES_PAGINA)),
            'carrinho' => (int) round($this->valorDaAcao($acoes, self::ACOES_CARRINHO)),
            'checkout' => (int) round($this->valorDaAcao($acoes, self::ACOES_CHECKOUT)),
        ];
    }

    private function centavosEmReais(mixed $centavos): ?float
    {
        return is_numeric($centavos) && (int) $centavos > 0 ? round((int) $centavos / 100, 2) : null;
    }

    /**
     * Busca estados e cidades do Brasil na Meta (chave usada no targeting).
     *
     * @return list<array{key: string, nome: string, tipo: string}>
     *
     * @throws MetaAdsException
     */
    public function buscarLocalizacoes(string $termo): array
    {
        $resposta = $this->get('search', [
            'type' => 'adgeolocation',
            'q' => $termo,
            'location_types' => json_encode(['region', 'city']),
            'country_code' => 'BR',
            'limit' => 10,
        ]);

        return collect($resposta['data'] ?? [])
            ->map(fn (array $item) => [
                'key' => (string) $item['key'],
                'nome' => trim(($item['name'] ?? '').(($item['type'] ?? '') === 'city' && filled($item['region'] ?? null) ? ', '.$item['region'] : '')),
                'tipo' => ($item['type'] ?? '') === 'city' ? 'cidade' : 'estado',
            ])
            ->values()
            ->all();
    }

    public function linkGerenciador(MetaAdsCampanha $campanha): ?string
    {
        $conta = preg_replace('/^act_/', '', (string) config('services.meta_ads.ad_account_id'));

        if ($conta === '') {
            return null;
        }

        $url = 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act='.urlencode($conta);

        return $campanha->meta_campaign_id
            ? $url.'&selected_campaign_ids='.urlencode($campanha->meta_campaign_id)
            : $url;
    }

    /** Campanha aberta no Gerenciador (linha da campanha no espelho). */
    public function linkCampanhaGerenciador(string $campaignId): ?string
    {
        $conta = preg_replace('/^act_/', '', (string) config('services.meta_ads.ad_account_id'));

        return $conta === '' ? null
            : 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act='.urlencode($conta).'&selected_campaign_ids='.urlencode($campaignId);
    }

    /** Conjunto aberto no Gerenciador (cartões do "O que fazer agora"). */
    public function linkConjuntoGerenciador(string $adsetId): ?string
    {
        $conta = preg_replace('/^act_/', '', (string) config('services.meta_ads.ad_account_id'));

        return $conta === '' ? null
            : 'https://adsmanager.facebook.com/adsmanager/manage/adsets?act='.urlencode($conta).'&selected_adset_ids='.urlencode($adsetId);
    }

    /** Anúncio aberto no Gerenciador (espelho, anúncio criado direto lá). */
    public function linkAnuncioGerenciador(string $adId): ?string
    {
        $conta = preg_replace('/^act_/', '', (string) config('services.meta_ads.ad_account_id'));

        return $conta === '' ? null
            : 'https://adsmanager.facebook.com/adsmanager/manage/ads?act='.urlencode($conta).'&selected_ad_ids='.urlencode($adId);
    }

    // Sincronizar com a Meta (pedido do Lira 2026-10-20) --------------------

    /**
     * Traz da Meta para o banco o que foi mudado direto no Gerenciador:
     * nome da campanha, do conjunto e do anúncio, orçamento diário, status
     * (ativa/pausada), criativo em uso e o texto e o LINK DE DESTINO de cada
     * anúncio (a Caixa Sanfonada e a Parafusadeira tiveram o link corrigido
     * lá e o banco seguia com o antigo). Só LÊ a Meta; nada é enviado.
     *
     * Atualiza só o que já existe no KazaKora (casado pelos ids meta_*).
     * Campanha sendo enviada, conjunto subindo e anúncio com troca de
     * arquivo pendente ficam de fora, para não brigar com o envio. O link
     * comum a todos os anúncios da campanha vira o link padrão dela (e o
     * produto, quando o link é de um produto da loja); o anúncio só guarda
     * link próprio quando é diferente do padrão.
     *
     * @return array{campanhas: int, conjuntos: int, anuncios: int, links: int, pulados: int, fora_da_meta: int}
     *
     * @throws MetaAdsException
     */
    public function sincronizar(): array
    {
        $resumo = ['campanhas' => 0, 'conjuntos' => 0, 'anuncios' => 0, 'links' => 0, 'pulados' => 0, 'fora_da_meta' => 0];

        $locais = MetaAdsCampanha::query()
            ->whereNotNull('meta_campaign_id')
            ->with(['conjuntos', 'videos'])
            ->get();
        if ($locais->isEmpty()) {
            return $resumo;
        }

        $filtro = ['filtering' => json_encode([[
            'field' => 'campaign.id', 'operator' => 'IN', 'value' => $locais->pluck('meta_campaign_id')->map(fn ($id) => (string) $id)->values()->all(),
        ]])];
        $campanhas = collect($this->getPaginado($this->conta().'/campaigns', $filtro + ['fields' => 'id,name,status,effective_status,daily_budget', 'limit' => 100]))
            ->keyBy(fn ($c) => (string) $c['id']);
        $conjuntos = collect($this->getPaginado($this->conta().'/adsets', $filtro + ['fields' => 'id,name,campaign_id,daily_budget', 'limit' => 200]))
            ->keyBy(fn ($s) => (string) $s['id']);
        $anuncios = collect($this->getPaginado($this->conta().'/ads', $filtro + [
            'fields' => 'id,name,adset_id,effective_status,creative{id,object_story_spec,asset_feed_spec}',
            'limit' => 200,
        ]))->keyBy(fn ($a) => (string) $a['id']);

        $produtos = [];
        $produtoDoLink = function (?string $link) use (&$produtos): ?int {
            $slug = preg_match('#^https?://(www\.)?kazakora\.com/produtos/([^/?\#]+)#', (string) $link, $m) ? $m[2] : null;

            return $slug === null ? null
                : ($produtos[$slug] ??= Product::query()->where('slug', $slug)->value('id'));
        };

        foreach ($locais as $campanha) {
            $naMeta = $campanhas->get((string) $campanha->meta_campaign_id);
            if (! $naMeta) {
                $resumo['fora_da_meta']++;

                continue;
            }
            if ($campanha->status === MetaAdsCampanha::STATUS_ENVIANDO) {
                $resumo['pulados']++;

                continue;
            }

            DB::transaction(function () use ($campanha, $naMeta, $conjuntos, $anuncios, $produtoDoLink, &$resumo) {
                // Anúncios que dá para sincronizar, com o criativo lido da Meta.
                $pares = $campanha->videos
                    ->filter(fn (MetaAdsVideo $v) => $v->estaNaMeta())
                    ->map(fn (MetaAdsVideo $v) => [$v, $anuncios->get((string) $v->meta_ad_id)]);
                $resumo['fora_da_meta'] += $pares->filter(fn ($p) => $p[1] === null)->count();
                $pares = $pares->filter(fn ($p) => $p[1] !== null);
                $resumo['pulados'] += $pares->filter(fn ($p) => $p[0]->temEdicaoPendente())->count();
                $pares = $pares->reject(fn ($p) => $p[0]->temEdicaoPendente());
                $textos = $pares->mapWithKeys(fn ($p) => [$p[0]->id => self::textoDoCriativo($p[1]['creative'] ?? [])]);

                // Campanha: nome, orçamento, status e o link padrão.
                $mudou = ['nome' => (string) $naMeta['name']];
                $diario = $this->centavosEmReais($naMeta['daily_budget'] ?? null);
                if ($diario !== null && $campanha->orcamentoDaCampanha()) {
                    $mudou['orcamento_diario'] = $diario;
                }
                if (in_array($campanha->status, [MetaAdsCampanha::STATUS_ATIVA, MetaAdsCampanha::STATUS_ENVIADA_PAUSADA], true)) {
                    $mudou['status'] = match ($naMeta['status'] ?? null) {
                        'ACTIVE' => MetaAdsCampanha::STATUS_ATIVA,
                        'PAUSED' => MetaAdsCampanha::STATUS_ENVIADA_PAUSADA,
                        default => $campanha->status,
                    };
                }
                $links = $textos->pluck('link')->filter()->unique();
                if ($links->count() === 1 && $links->first() !== $campanha->link_destino) {
                    $mudou['link_destino'] = $links->first();
                    $mudou['produto_id'] = $produtoDoLink($links->first()) ?? $campanha->produto_id;
                    $resumo['links']++;
                }
                if ($this->gravarSeMudou($campanha, $mudou)) {
                    $resumo['campanhas']++;
                }

                // Conjuntos: na Meta o nome é "campanha | conjunto"; aqui fica só o do conjunto.
                foreach ($campanha->conjuntos as $conjunto) {
                    $set = $conjuntos->get((string) $conjunto->meta_adset_id);
                    if (! $set || $conjunto->enviando) {
                        continue;
                    }
                    $prefixo = $campanha->nome.' | ';
                    $nome = (string) $set['name'];
                    $mudou = ['nome' => mb_substr(str_starts_with($nome, $prefixo) ? mb_substr($nome, mb_strlen($prefixo)) : $nome, 0, 120)];
                    $diario = $this->centavosEmReais($set['daily_budget'] ?? null);
                    if ($diario !== null && ! $campanha->orcamentoDaCampanha()) {
                        $mudou['orcamento_diario'] = $diario;
                    }
                    if ($this->gravarSeMudou($conjunto, $mudou)) {
                        $resumo['conjuntos']++;
                    }
                }
                if (! $campanha->orcamentoDaCampanha()) {
                    $this->gravarSeMudou($campanha, ['orcamento_diario' => round((float) $campanha->conjuntos()->sum('orcamento_diario'), 2)]);
                }

                // Anúncios: nome, criativo e o que está nele (vazio = padrão da campanha).
                foreach ($pares as [$video, $ad]) {
                    $texto = $textos[$video->id];
                    $mudou = [];
                    if ((string) $ad['name'] !== $this->nomeDoAnuncio($campanha, $video)) {
                        $mudou['nome'] = mb_substr((string) $ad['name'], 0, 255);
                    }
                    if (filled($ad['creative']['id'] ?? null)) {
                        $mudou['meta_creative_id'] = (string) $ad['creative']['id'];
                    }
                    $atual = $video->criativo($campanha);
                    foreach (['link' => 'link_destino', 'texto' => 'texto_principal', 'titulo' => 'titulo', 'descricao' => 'descricao', 'chamada' => 'chamada'] as $chave => $coluna) {
                        if (filled($texto[$chave]) && $texto[$chave] !== $atual[$chave]) {
                            $mudou[$coluna] = $texto[$chave];
                        }
                    }
                    if (isset($mudou['link_destino'])) {
                        $mudou['produto_id'] = $produtoDoLink($mudou['link_destino']) ?? $video->produto_id;
                        $resumo['links']++;
                    }
                    if ($this->gravarSeMudou($video, $mudou)) {
                        $resumo['anuncios']++;
                    }
                }
            });
        }

        $this->esquecerEspelho();

        return $resumo;
    }

    /**
     * Link, texto, título, descrição e botão do criativo como a Meta guarda:
     * no video_data/link_data ou, com várias opções, no asset_feed_spec (a
     * primeira de cada é a principal).
     *
     * @param  array<string, mixed>  $criativo
     * @return array{link: ?string, texto: ?string, titulo: ?string, descricao: ?string, chamada: ?string}
     */
    public static function textoDoCriativo(array $criativo): array
    {
        $spec = $criativo['object_story_spec'] ?? [];
        $dados = $spec['video_data'] ?? $spec['link_data'] ?? [];
        $feed = $criativo['asset_feed_spec'] ?? [];
        $primeiro = fn (string $chave) => $feed[$chave][0]['text'] ?? null;
        $limpo = fn ($valor) => is_string($valor) && trim($valor) !== '' ? trim($valor) : null;

        return [
            'link' => $limpo($dados['call_to_action']['value']['link'] ?? $dados['link'] ?? $feed['link_urls'][0]['website_url'] ?? null),
            'texto' => $limpo($dados['message'] ?? $primeiro('bodies')),
            'titulo' => $limpo($dados['title'] ?? $dados['name'] ?? $primeiro('titles')),
            'descricao' => $limpo($dados['link_description'] ?? $dados['description'] ?? $primeiro('descriptions')),
            'chamada' => $limpo($dados['call_to_action']['type'] ?? $feed['call_to_action_types'][0] ?? null),
        ];
    }

    /** Grava só os campos que mudaram; devolve se algo mudou. */
    private function gravarSeMudou(MetaAdsCampanha|MetaAdsConjunto|MetaAdsVideo $modelo, array $campos): bool
    {
        $modelo->forceFill($campos);
        if (! $modelo->isDirty()) {
            return false;
        }
        $modelo->save();

        return true;
    }

    // Payloads ---------------------------------------------------------------

    /** @return array<string, mixed> */
    public function payloadCampanha(MetaAdsCampanha $campanha): array
    {
        $payload = [
            'name' => $campanha->nome,
            'objective' => $campanha->objetivo,
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
        ];

        if ($campanha->orcamentoDaCampanha()) {
            // Advantage campaign budget: a Meta distribui entre os conjuntos.
            $payload['daily_budget'] = (int) round((float) $campanha->orcamento_diario * 100);
            $payload['bid_strategy'] = 'LOWEST_COST_WITHOUT_CAP';
        } else {
            // Orçamento em cada conjunto; a Meta exige dizer se empresta entre eles.
            $payload['is_adset_budget_sharing_enabled'] = 'false';
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function payloadConjunto(MetaAdsCampanha $campanha, MetaAdsConjunto $conjunto): array
    {
        $payload = [
            'name' => $campanha->nome.' | '.$conjunto->nome,
            'campaign_id' => $campanha->meta_campaign_id,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => self::OTIMIZACAO[$campanha->objetivo],
            // Sem publisher_platforms = posicionamentos automáticos.
            'targeting' => json_encode($this->targeting($campanha, $conjunto)),
            'start_time' => $this->inicioDoConjunto($campanha)->toIso8601String(),
            'status' => 'PAUSED',
        ];

        if (! $campanha->orcamentoDaCampanha()) {
            $payload['daily_budget'] = (int) round((float) $conjunto->orcamento_diario * 100);
            $payload['bid_strategy'] = 'LOWEST_COST_WITHOUT_CAP';
        }

        if (in_array($campanha->objetivo, ['OUTCOME_SALES', 'OUTCOME_TRAFFIC'], true)) {
            // Atribuição dita, não o padrão do dia da Meta: a do Gerenciador
            // na Árvore de Natal (2026-10-18) é 7 dias após o clique.
            $payload['attribution_spec'] = json_encode(self::ATRIBUICAO);
        }

        if ($campanha->fim) {
            $payload['end_time'] = Carbon::parse($campanha->fim->toDateString(), self::FUSO)->endOfDay()->toIso8601String();
        }

        if (in_array($campanha->objetivo, ['OUTCOME_SALES', 'OUTCOME_TRAFFIC'], true)) {
            $payload['destination_type'] = 'WEBSITE';
        }

        if ($campanha->objetivo === 'OUTCOME_SALES') {
            $payload['promoted_object'] = json_encode([
                'pixel_id' => (string) PixelMeta::id(),
                'custom_event_type' => 'PURCHASE',
            ]);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function targeting(MetaAdsCampanha $campanha, ?MetaAdsConjunto $conjunto = null): array
    {
        $publico = ($conjunto ?? new MetaAdsConjunto)->publico($campanha);
        $local = $publico['localizacoes'];
        $regioes = collect($local['regioes'] ?? [])->map(fn ($r) => ['key' => (string) $r['key']])->values()->all();
        $cidades = collect($local['cidades'] ?? [])->map(function ($c) {
            $cidade = ['key' => (string) $c['key']];
            if (! empty($c['raio_km'])) {
                $cidade['radius'] = (int) $c['raio_km'];
                $cidade['distance_unit'] = 'kilometer';
            }

            return $cidade;
        })->values()->all();

        // A Meta recusa país junto com estado/cidade dele (locais
        // sobrepostos): com estado ou cidade escolhidos, vai só eles.
        $geo = [];
        if ($regioes !== []) {
            $geo['regions'] = $regioes;
        }
        if ($cidades !== []) {
            $geo['cities'] = $cidades;
        }
        if ($geo === []) {
            $geo['countries'] = ['BR'];
        }
        // Igual ao que o Gerenciador grava: mora, esteve há pouco ou frequenta.
        $geo['location_types'] = ['home', 'recent', 'frequently_in'];

        $targeting = [
            'geo_locations' => $geo,
            'age_min' => $publico['idade_min'],
            'age_max' => $publico['idade_max'],
            // Público exatamente como montado na tela (sem o Advantage+
            // ampliar idade e gênero por conta própria).
            'targeting_automation' => ['advantage_audience' => 0],
        ];

        if ($publico['genero'] === 'masculino') {
            $targeting['genders'] = [1];
        } elseif ($publico['genero'] === 'feminino') {
            $targeting['genders'] = [2];
        }

        return $targeting;
    }

    /**
     * Cria o criativo. Se a Meta recusar as melhorias automáticas
     * (degrees_of_freedom_spec muda de nome entre versões da API), tenta de
     * novo sem elas em vez de travar o envio.
     *
     * @throws MetaAdsException
     */
    private function criarCriativo(MetaAdsCampanha $campanha, MetaAdsVideo $video): string
    {
        $payload = $this->payloadCriativo($campanha, $video);

        try {
            $resposta = $this->post($this->conta().'/adcreatives', $payload);
        } catch (MetaAdsException $erro) {
            if (! preg_match('/degrees_of_freedom|creative_features|enroll_status|contextual/i', $erro->getMessage())) {
                throw $erro;
            }

            unset($payload['degrees_of_freedom_spec'], $payload['contextual_multi_ads']);
            $resposta = $this->post($this->conta().'/adcreatives', $payload);
        }

        return (string) $resposta['id'];
    }

    /**
     * Anúncio que já está no conjunto com a mesma mídia (vídeo ou imagem).
     *
     * @return array{ad_id: string, creative_id: string}|null
     *
     * @throws MetaAdsException
     */
    private function anuncioJaNoConjunto(string $adsetId, MetaAdsVideo $video): ?array
    {
        $midia = (string) ($video->ehImagem() ? $video->meta_image_hash : $video->meta_video_id);
        if ($midia === '') {
            return null;
        }

        $anuncios = $this->getPaginado($adsetId.'/ads', [
            'fields' => 'id,effective_status,creative{id,object_story_spec}',
            'limit' => 100,
        ]);

        foreach ($anuncios as $anuncio) {
            if (in_array($anuncio['effective_status'] ?? '', ['DELETED', 'ARCHIVED'], true)) {
                continue;
            }

            $spec = $anuncio['creative']['object_story_spec'] ?? [];
            $daMidia = $video->ehImagem()
                ? ($spec['link_data']['image_hash'] ?? null)
                : ($spec['video_data']['video_id'] ?? null);

            if ((string) $daMidia === $midia && filled($anuncio['creative']['id'] ?? null)) {
                return ['ad_id' => (string) $anuncio['id'], 'creative_id' => (string) $anuncio['creative']['id']];
            }
        }

        return null;
    }

    /** Parâmetros de UTM que a Meta cola no link (url_tags), com o nome real da campanha e do anúncio. */
    public const URL_TAGS = 'utm_source=meta&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.name}}';

    /** Janela de atribuição do conjunto: 7 dias após o clique. */
    public const ATRIBUICAO = [['event_type' => 'CLICK_THROUGH', 'window_days' => 7]];

    /**
     * Melhorias automáticas do criativo, iguais às que o Lira deixou ligadas
     * no Gerenciador (Árvore de Natal, 2026-10-18). Ficam de fora a dublagem
     * automática e o Advantage+ criativo (mexem no texto e na voz).
     */
    public const MELHORIAS_DO_CRIATIVO = [
        'enhance_cta' => 'OPT_IN',
        'inline_comment' => 'OPT_IN',
        'video_auto_crop' => 'OPT_IN',
        'video_filtering' => 'OPT_IN',
        'video_uncrop' => 'OPT_IN',
        'text_optimizations' => 'OPT_OUT',
    ];

    /** @return array<string, mixed> */
    public function payloadCriativo(MetaAdsCampanha $campanha, MetaAdsVideo $video): array
    {
        $criativo = $video->criativo($campanha);
        $chamada = [
            'type' => $criativo['chamada'],
            'value' => ['link' => $criativo['link']],
        ];
        $semVazio = fn (array $dados) => array_filter($dados, fn ($valor) => $valor !== null && $valor !== '');

        $spec = ['page_id' => (string) config('services.meta_ads.page_id')];

        if ($video->ehImagem()) {
            $spec['link_data'] = $semVazio([
                'image_hash' => (string) $video->meta_image_hash,
                'link' => $criativo['link'],
                'message' => $criativo['texto'],
                'name' => $criativo['titulo'],
                'description' => $criativo['descricao'],
                'call_to_action' => $chamada,
            ]);
        } else {
            $spec['video_data'] = $semVazio([
                'video_id' => (string) $video->meta_video_id,
                'image_url' => $video->meta_thumbnail_url,
                'message' => $criativo['texto'],
                'title' => $criativo['titulo'],
                'link_description' => $criativo['descricao'],
                'call_to_action' => $chamada,
            ]);
        }

        if (filled(config('services.meta_ads.instagram_actor_id'))) {
            // Desde a v22 o campo se chama instagram_user_id (o antigo
            // instagram_actor_id foi descontinuado); o valor é o mesmo id.
            $spec['instagram_user_id'] = (string) config('services.meta_ads.instagram_actor_id');
        }

        $payload = [
            'name' => $campanha->nome.' | '.$this->rotuloDoAnuncio($video).' | criativo',
            'object_story_spec' => json_encode($spec),
            'url_tags' => self::URL_TAGS,
            'degrees_of_freedom_spec' => json_encode(['creative_features_spec' => array_map(
                fn (string $estado) => ['enroll_status' => $estado],
                self::MELHORIAS_DO_CRIATIVO,
            )]),
            // Vários anúncios juntos no feed (ligado nos 3 da Árvore de Natal).
            'contextual_multi_ads' => json_encode(['enroll_status' => 'OPT_IN']),
        ];

        // Várias opções de texto, título e descrição: a Meta testa as
        // combinações (como no Gerenciador). A principal vai primeiro.
        $extras = $campanha->textosAlternativos();
        if ($extras['textos'] !== [] || $extras['titulos'] !== [] || $extras['descricoes'] !== []) {
            $opcoes = fn (?string $principal, array $outras, int $max) => array_map(
                fn (string $t) => ['text' => $t],
                array_values(array_slice(array_unique(array_filter([trim((string) $principal), ...$outras], fn ($t) => $t !== '')), 0, $max)),
            );

            // Igual ao Gerenciador: os textos ficam só no asset_feed_spec.
            $chave = $video->ehImagem() ? 'link_data' : 'video_data';
            unset($spec[$chave]['message'], $spec[$chave]['title'], $spec[$chave]['name'], $spec[$chave]['description'], $spec[$chave]['link_description']);
            $payload['object_story_spec'] = json_encode($spec);

            $payload['asset_feed_spec'] = json_encode(array_filter([
                'bodies' => $opcoes($criativo['texto'], $extras['textos'], 5),
                'titles' => $opcoes($criativo['titulo'], $extras['titulos'], 5),
                'descriptions' => $opcoes($criativo['descricao'], $extras['descricoes'], 5),
                'optimization_type' => 'DEGREES_OF_FREEDOM',
            ]));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function payloadAnuncio(MetaAdsCampanha $campanha, MetaAdsVideo $video, MetaAdsConjunto $conjunto): array
    {
        return [
            'name' => $this->nomeDoAnuncio($campanha, $video, $conjunto),
            'adset_id' => $video->meta_adset_id,
            'creative' => json_encode(['creative_id' => $video->meta_creative_id]),
            'status' => 'PAUSED',
        ];
    }

    private function rotuloDoAnuncio(MetaAdsVideo $video): string
    {
        return 'anúncio '.($video->ordem + 1).' ('.mb_substr(pathinfo($video->nome_original, PATHINFO_FILENAME), 0, 60).')';
    }

    /** Início no dia escolhido; se já passou, agora (a Meta recusa início no passado). */
    private function inicioDoConjunto(MetaAdsCampanha $campanha): Carbon
    {
        $inicio = Carbon::parse($campanha->inicio->toDateString(), self::FUSO)->startOfDay();

        return $inicio->isPast() ? Carbon::now(self::FUSO)->addMinutes(5) : $inicio;
    }

    // Vídeo ------------------------------------------------------------------

    /**
     * Versão 9:16 do vídeo horizontal, na mesma pasta do original.
     *
     * @throws MetaAdsException
     */
    private function deixarVertical(MetaAdsVideo $video): string
    {
        $disco = Storage::disk('local');
        if (! $disco->exists($video->path)) {
            throw new MetaAdsException('O arquivo do vídeo não está mais no servidor.');
        }

        $destino = dirname($video->path).'/'.pathinfo($video->path, PATHINFO_FILENAME).'-9x16.mp4';

        try {
            app(FormatoDeVideo::class)->deixarVertical($disco->path($video->path), $disco->path($destino));
        } catch (\RuntimeException $e) {
            report($e);

            throw new MetaAdsException('Não consegui deixar o vídeo horizontal no formato 9:16. Exporte o vídeo em pé (1080x1920) e envie de novo.');
        }

        return $destino;
    }

    /**
     * Sobe o arquivo para a biblioteca de vídeos da conta (advideos).
     *
     * @throws MetaAdsException
     */
    private function subirVideo(MetaAdsVideo $video): string
    {
        $this->exigirConfiguracao();

        $disco = Storage::disk('local');
        if (! $disco->exists($video->arquivoParaMeta())) {
            throw new MetaAdsException('O arquivo do vídeo não está mais no servidor.');
        }

        $arquivo = fopen($disco->path($video->arquivoParaMeta()), 'r');

        try {
            $resposta = $this->cliente()
                ->timeout(600)
                ->attach('source', $arquivo, $video->nome_original)
                ->post($this->url($this->conta().'/advideos'), ['name' => $video->nome_original]);
        } catch (ConnectionException) {
            throw new MetaAdsException('Sem conexão com a Meta ao enviar o vídeo. Tente de novo em alguns minutos.');
        } finally {
            if (is_resource($arquivo)) {
                fclose($arquivo);
            }
        }

        $corpo = $this->ler($resposta);

        if (blank($corpo['id'] ?? null)) {
            throw new MetaAdsException('A Meta não devolveu o id do vídeo.');
        }

        return (string) $corpo['id'];
    }

    /**
     * Sobe a imagem para a biblioteca da conta (adimages).
     *
     * @return array{0: string, 1: ?string} hash e url da imagem na Meta
     *
     * @throws MetaAdsException
     */
    private function subirImagem(MetaAdsVideo $video): array
    {
        $this->exigirConfiguracao();

        $disco = Storage::disk('local');
        if (! $disco->exists($video->path)) {
            throw new MetaAdsException('O arquivo da imagem não está mais no servidor.');
        }

        try {
            $resposta = $this->cliente()
                ->timeout(120)
                ->attach('filename', $disco->get($video->path), $video->nome_original)
                ->post($this->url($this->conta().'/adimages'));
        } catch (ConnectionException) {
            throw new MetaAdsException('Sem conexão com a Meta ao enviar a imagem. Tente de novo em alguns minutos.');
        }

        $imagem = collect($this->ler($resposta)['images'] ?? [])->first();

        if (blank($imagem['hash'] ?? null)) {
            throw new MetaAdsException('A Meta não devolveu o código da imagem.');
        }

        return [(string) $imagem['hash'], $imagem['url'] ?? null];
    }

    /**
     * Capa do vídeo quando a Meta terminou de processar; null enquanto
     * processa.
     *
     * @throws MetaAdsException quando a Meta recusou o vídeo
     */
    private function capaSeProcessado(string $videoId): ?string
    {
        $resposta = $this->get($videoId, ['fields' => 'status']);
        $estado = $resposta['status']['video_status'] ?? null;

        if ($estado === 'error') {
            throw new MetaAdsException('A Meta não conseguiu processar o vídeo. Confira o formato (MP4 ou MOV) e envie outro arquivo.');
        }

        if ($estado !== 'ready') {
            return null;
        }

        $capas = collect($this->get($videoId.'/thumbnails', [])['data'] ?? []);
        $capa = $capas->firstWhere('is_preferred', true) ?? $capas->first();

        return $capa['uri'] ?? null;
    }

    // HTTP -------------------------------------------------------------------

    private function conta(): string
    {
        $conta = (string) config('services.meta_ads.ad_account_id');

        return str_starts_with($conta, 'act_') ? $conta : 'act_'.$conta;
    }

    private function cliente(): PendingRequest
    {
        return Http::withToken((string) config('services.meta_ads.access_token'))
            ->acceptJson()
            ->timeout(60);
    }

    private function url(string $caminho): string
    {
        return rtrim((string) config('services.meta_ads.graph_url'), '/').'/'.ltrim($caminho, '/');
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws MetaAdsException
     */
    private function post(string $caminho, array $dados): array
    {
        $this->exigirConfiguracao();

        try {
            $resposta = $this->cliente()->asForm()->post($this->url($caminho), $dados);
        } catch (ConnectionException) {
            throw new MetaAdsException('Sem conexão com a Meta. Tente de novo em alguns minutos.');
        }

        return $this->ler($resposta);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws MetaAdsException
     */
    private function get(string $caminho, array $params): array
    {
        $this->exigirConfiguracao();

        try {
            $resposta = $this->cliente()->get($this->url($caminho), $params);
        } catch (ConnectionException) {
            throw new MetaAdsException('Sem conexão com a Meta. Tente de novo em alguns minutos.');
        }

        return $this->ler($resposta);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     *
     * @throws MetaAdsException
     */
    private function getPaginado(string $caminho, array $params): array
    {
        $resposta = $this->get($caminho, $params);
        $linhas = $resposta['data'] ?? [];
        $paginas = 0;

        while (($proxima = $resposta['paging']['next'] ?? null) && $paginas < 50) {
            $paginas++;

            try {
                $resposta = $this->ler($this->cliente()->get($proxima));
            } catch (ConnectionException) {
                throw new MetaAdsException('Sem conexão com a Meta. Tente de novo em alguns minutos.');
            }

            $linhas = array_merge($linhas, $resposta['data'] ?? []);
        }

        return $linhas;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MetaAdsException
     */
    private function ler(Response $resposta): array
    {
        $corpo = $resposta->json();

        if ($resposta->successful() && is_array($corpo) && ! isset($corpo['error'])) {
            return $corpo;
        }

        throw new MetaAdsException($this->mensagemDeErro(is_array($corpo) ? $corpo : [], $resposta->status()));
    }

    /** @param  array<string, mixed>  $corpo */
    private function mensagemDeErro(array $corpo, int $http): string
    {
        $erro = $corpo['error'] ?? [];
        $texto = $erro['error_user_msg'] ?? $erro['message'] ?? "A Meta respondeu com erro HTTP {$http}.";

        if (filled($erro['error_user_title'] ?? null) && filled($erro['error_user_msg'] ?? null)) {
            $texto = $erro['error_user_title'].'. '.$erro['error_user_msg'];
        }

        // App da Meta em modo de desenvolvimento: deixa criar campanha e
        // conjunto, mas recusa o criativo (Árvore de Natal, 2026-10-18).
        if ((int) ($erro['error_subcode'] ?? 0) === 1885183) {
            $texto = 'O app da Meta usado pelo KazaKora (KazaKora Ads) está em modo de desenvolvimento e a Meta não deixa ele criar anúncio. '
                .'No painel de desenvolvedor da Meta, ponha o app em modo Ao vivo e clique em enviar de novo: continua de onde parou.';
        }

        if (isset($erro['code'])) {
            $texto .= ' (código '.$erro['code'].(isset($erro['error_subcode']) ? '/'.$erro['error_subcode'] : '').')';
        }

        // Garantia extra: se algum dia a Meta ecoar o token, ele não sai daqui.
        $token = (string) config('services.meta_ads.access_token');
        if ($token !== '') {
            $texto = str_replace($token, '***', $texto);
        }

        return mb_substr($texto, 0, 1000);
    }

    private function exigirConfiguracao(): void
    {
        if (! $this->configurado()) {
            throw new MetaAdsException('Meta não configurado: faltam '.implode(', ', $this->faltando()).'.');
        }
    }

    /** @param  list<array{action_type?: string, value?: mixed}>  $acoes */
    private function valorDeCompra(array $acoes): float
    {
        return $this->valorDaAcao($acoes, self::ACOES_DE_COMPRA);
    }

    /**
     * Primeiro tipo da lista que a Meta mandou (não soma: omni_* já inclui os outros).
     *
     * @param  list<array{action_type?: string, value?: mixed}>  $acoes
     * @param  list<string>  $tipos
     */
    private function valorDaAcao(array $acoes, array $tipos): float
    {
        $porTipo = collect($acoes)->keyBy('action_type');

        foreach ($tipos as $tipo) {
            if ($porTipo->has($tipo)) {
                return (float) ($porTipo[$tipo]['value'] ?? 0);
            }
        }

        return 0.0;
    }
}

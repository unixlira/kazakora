<?php

namespace App\Services\Meta;

use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsConjunto;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Support\PixelMeta;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
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
     * @return self::ENVIO_*
     *
     * @throws MetaAdsException
     */
    public function avancarEnvio(MetaAdsCampanha $campanha): string
    {
        $campanha->loadMissing(['conjuntos', 'videos']);
        $passo = 'campanha';
        $video = null;

        try {
            if (blank($campanha->meta_campaign_id)) {
                $resposta = $this->post($this->conta().'/campaigns', $this->payloadCampanha($campanha));
                $campanha->forceFill(['meta_campaign_id' => (string) $resposta['id']])->save();
            }

            // 1. Sobe todas as mídias primeiro: a Meta processa os vídeos em paralelo.
            foreach ($campanha->videos as $video) {
                $passo = "envio do {$this->rotuloDoAnuncio($video)}";
                if ($video->ehImagem() && blank($video->meta_image_hash)) {
                    [$hash, $url] = $this->subirImagem($video);
                    $video->forceFill(['meta_image_hash' => $hash, 'meta_thumbnail_url' => $url, 'ultimo_erro' => null])->save();

                    return self::ENVIO_CONTINUAR;
                }
                if (! $video->ehImagem() && blank($video->meta_video_id)) {
                    $video->forceFill(['meta_video_id' => $this->subirVideo($video), 'ultimo_erro' => null])->save();

                    return self::ENVIO_CONTINUAR;
                }
            }

            // 2. Espera o processamento dos vídeos e pega a capa que a Meta escolheu.
            $todosProntos = true;
            foreach ($campanha->videos as $video) {
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

            // 3. Cada conjunto e, dentro dele, criativo e anúncio de cada mídia.
            foreach ($campanha->conjuntos as $conjunto) {
                $anuncios = $campanha->videos->where('conjunto_id', $conjunto->id);
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

                    $passo = "criativo do {$this->rotuloDoAnuncio($video)} ({$conjunto->nome})";
                    if (blank($video->meta_creative_id)) {
                        $resposta = $this->post($this->conta().'/adcreatives', $this->payloadCriativo($campanha, $video));
                        $video->forceFill(['meta_creative_id' => (string) $resposta['id']])->save();
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
            } elseif (isset($conjunto) && str_starts_with($passo, 'conjunto ')) {
                $conjunto->forceFill(['ultimo_erro' => mb_substr($erro->getMessage(), 0, 1000)])->save();
            }
            $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ERRO, 'ultimo_erro' => $mensagem])->save();

            throw $erro;
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

        // Vários anúncios apontam para o mesmo conjunto: cada id uma vez só.
        $ids = array_values(array_unique(array_merge(
            $campanha->videos->pluck('meta_ad_id')->filter()->all(),
            $campanha->conjuntos->pluck('meta_adset_id')->filter()->all(),
            $campanha->videos->pluck('meta_adset_id')->filter()->all(),
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
        $geo['location_types'] = ['home', 'recent'];

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

        return [
            'name' => $campanha->nome.' | '.$this->rotuloDoAnuncio($video).' | criativo',
            'object_story_spec' => json_encode($spec),
        ];
    }

    /** @return array<string, mixed> */
    public function payloadAnuncio(MetaAdsCampanha $campanha, MetaAdsVideo $video, MetaAdsConjunto $conjunto): array
    {
        return [
            'name' => $campanha->nome.' | '.$conjunto->nome.' | '.$this->rotuloDoAnuncio($video),
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
     * Sobe o arquivo para a biblioteca de vídeos da conta (advideos).
     *
     * @throws MetaAdsException
     */
    private function subirVideo(MetaAdsVideo $video): string
    {
        $this->exigirConfiguracao();

        $disco = Storage::disk('local');
        if (! $disco->exists($video->path)) {
            throw new MetaAdsException('O arquivo do vídeo não está mais no servidor.');
        }

        $arquivo = fopen($disco->path($video->path), 'r');

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
        $porTipo = collect($acoes)->keyBy('action_type');

        foreach (self::ACOES_DE_COMPRA as $tipo) {
            if ($porTipo->has($tipo)) {
                return (float) ($porTipo[$tipo]['value'] ?? 0);
            }
        }

        return 0.0;
    }
}

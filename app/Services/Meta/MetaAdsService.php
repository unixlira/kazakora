<?php

namespace App\Services\Meta;

use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Meta ADS (2026-10-13): envio de campanhas de vídeo pela Marketing API.
 *
 * Estrutura na Meta: uma campanha e, para cada vídeo, um conjunto de
 * anúncios com a sua parte do orçamento diário (divisão igual, pedido do
 * Lira), um criativo de vídeo e um anúncio.
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
        return filled(config('services.meta_ads.pixel_id'));
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
            $problemas[] = 'Objetivo Vendas precisa do pixel da Meta (META_ADS_PIXEL_ID).';
        }

        if (! isset(self::OTIMIZACAO[$campanha->objetivo])) {
            $problemas[] = 'Objetivo inválido.';
        }

        $quantidade = $campanha->videos->count();
        if ($quantidade === 0) {
            $problemas[] = 'Envie pelo menos um vídeo.';
        } elseif ((float) $campanha->orcamento_diario / $quantidade < MetaAdsCampanha::MINIMO_POR_VIDEO) {
            $problemas[] = sprintf(
                'Orçamento baixo para %d vídeos: cada um precisa de pelo menos R$ %s por dia (total mínimo R$ %s).',
                $quantidade,
                number_format(MetaAdsCampanha::MINIMO_POR_VIDEO, 2, ',', '.'),
                number_format(MetaAdsCampanha::MINIMO_POR_VIDEO * $quantidade, 2, ',', '.'),
            );
        }

        foreach ($campanha->videos as $video) {
            if (blank($video->meta_video_id) && ! Storage::disk('local')->exists($video->path)) {
                $problemas[] = "O arquivo do vídeo {$video->nome_original} sumiu do servidor. Tire e envie de novo.";
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
        $campanha->loadMissing('videos');
        $passo = 'campanha';
        $video = null;

        try {
            if (blank($campanha->meta_campaign_id)) {
                $resposta = $this->post($this->conta().'/campaigns', $this->payloadCampanha($campanha));
                $campanha->forceFill(['meta_campaign_id' => (string) $resposta['id']])->save();
            }

            // 1. Sobe todos os vídeos primeiro: a Meta processa em paralelo.
            foreach ($campanha->videos as $video) {
                $passo = "envio do vídeo {$video->nome_original}";
                if (blank($video->meta_video_id)) {
                    $video->forceFill(['meta_video_id' => $this->subirVideo($video), 'ultimo_erro' => null])->save();

                    return self::ENVIO_CONTINUAR;
                }
            }

            // 2. Espera o processamento e pega a capa que a Meta escolheu.
            $todosProntos = true;
            foreach ($campanha->videos as $video) {
                $passo = "processamento do vídeo {$video->nome_original}";
                if (filled($video->meta_thumbnail_url)) {
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

            // 3. Um conjunto (com a parte do orçamento), criativo e anúncio por vídeo.
            $partes = MetaAdsCampanha::dividirOrcamento((float) $campanha->orcamento_diario, $campanha->videos->count());

            foreach ($campanha->videos->values() as $i => $video) {
                $passo = "conjunto de anúncios do vídeo {$video->nome_original}";
                if (blank($video->meta_adset_id)) {
                    $video->forceFill(['orcamento_diario' => $partes[$i] / 100])->save();
                    $resposta = $this->post($this->conta().'/adsets', $this->payloadConjunto($campanha, $video, $partes[$i]));
                    $video->forceFill(['meta_adset_id' => (string) $resposta['id']])->save();
                }

                $passo = "criativo do vídeo {$video->nome_original}";
                if (blank($video->meta_creative_id)) {
                    $resposta = $this->post($this->conta().'/adcreatives', $this->payloadCriativo($campanha, $video));
                    $video->forceFill(['meta_creative_id' => (string) $resposta['id']])->save();
                }

                $passo = "anúncio do vídeo {$video->nome_original}";
                if (blank($video->meta_ad_id)) {
                    $resposta = $this->post($this->conta().'/ads', $this->payloadAnuncio($campanha, $video));
                    $video->forceFill(['meta_ad_id' => (string) $resposta['id'], 'ultimo_erro' => null])->save();
                }
            }
        } catch (MetaAdsException $erro) {
            $mensagem = "Falhou no passo {$passo}: {$erro->getMessage()}";
            $video?->forceFill(['ultimo_erro' => mb_substr($erro->getMessage(), 0, 1000)])->save();
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
        $campanha->loadMissing('videos');

        $ids = array_merge(
            $campanha->videos->pluck('meta_ad_id')->filter()->all(),
            $campanha->videos->pluck('meta_adset_id')->filter()->all(),
            [$campanha->meta_campaign_id],
        );

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
        return [
            'name' => $campanha->nome,
            'objective' => $campanha->objetivo,
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
            // Orçamento fica em cada conjunto (um por vídeo), não na campanha:
            // é assim que a divisão igual entre os vídeos é garantida.
            'is_adset_budget_sharing_enabled' => 'false',
        ];
    }

    /** @return array<string, mixed> */
    public function payloadConjunto(MetaAdsCampanha $campanha, MetaAdsVideo $video, int $orcamentoCentavos): array
    {
        $payload = [
            'name' => $campanha->nome.' | '.$this->rotuloDoVideo($video),
            'campaign_id' => $campanha->meta_campaign_id,
            'daily_budget' => $orcamentoCentavos,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => self::OTIMIZACAO[$campanha->objetivo],
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            // Sem publisher_platforms = posicionamentos automáticos.
            'targeting' => json_encode($this->targeting($campanha)),
            'start_time' => $this->inicioDoConjunto($campanha)->toIso8601String(),
            'status' => 'PAUSED',
        ];

        if ($campanha->fim) {
            $payload['end_time'] = Carbon::parse($campanha->fim->toDateString(), self::FUSO)->endOfDay()->toIso8601String();
        }

        if (in_array($campanha->objetivo, ['OUTCOME_SALES', 'OUTCOME_TRAFFIC'], true)) {
            $payload['destination_type'] = 'WEBSITE';
        }

        if ($campanha->objetivo === 'OUTCOME_SALES') {
            $payload['promoted_object'] = json_encode([
                'pixel_id' => (string) config('services.meta_ads.pixel_id'),
                'custom_event_type' => 'PURCHASE',
            ]);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function targeting(MetaAdsCampanha $campanha): array
    {
        $local = $campanha->localizacoes ?? [];
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
            'age_min' => (int) $campanha->idade_min,
            'age_max' => (int) $campanha->idade_max,
            // Público exatamente como montado na tela (sem o Advantage+
            // ampliar idade e gênero por conta própria).
            'targeting_automation' => ['advantage_audience' => 0],
        ];

        if ($campanha->genero === 'masculino') {
            $targeting['genders'] = [1];
        } elseif ($campanha->genero === 'feminino') {
            $targeting['genders'] = [2];
        }

        return $targeting;
    }

    /** @return array<string, mixed> */
    public function payloadCriativo(MetaAdsCampanha $campanha, MetaAdsVideo $video): array
    {
        $videoData = array_filter([
            'video_id' => (string) $video->meta_video_id,
            'image_url' => $video->meta_thumbnail_url,
            'message' => $campanha->texto_principal,
            'title' => $campanha->titulo,
            'link_description' => $campanha->descricao,
            'call_to_action' => [
                'type' => $campanha->chamada,
                'value' => ['link' => $campanha->link_destino],
            ],
        ], fn ($valor) => $valor !== null && $valor !== '');

        $spec = [
            'page_id' => (string) config('services.meta_ads.page_id'),
            'video_data' => $videoData,
        ];

        if (filled(config('services.meta_ads.instagram_actor_id'))) {
            // Desde a v22 o campo se chama instagram_user_id (o antigo
            // instagram_actor_id foi descontinuado); o valor é o mesmo id.
            $spec['instagram_user_id'] = (string) config('services.meta_ads.instagram_actor_id');
        }

        return [
            'name' => $campanha->nome.' | '.$this->rotuloDoVideo($video).' | criativo',
            'object_story_spec' => json_encode($spec),
        ];
    }

    /** @return array<string, mixed> */
    public function payloadAnuncio(MetaAdsCampanha $campanha, MetaAdsVideo $video): array
    {
        return [
            'name' => $campanha->nome.' | '.$this->rotuloDoVideo($video),
            'adset_id' => $video->meta_adset_id,
            'creative' => json_encode(['creative_id' => $video->meta_creative_id]),
            'status' => 'PAUSED',
        ];
    }

    private function rotuloDoVideo(MetaAdsVideo $video): string
    {
        return 'vídeo '.($video->ordem + 1).' ('.mb_substr(pathinfo($video->nome_original, PATHINFO_FILENAME), 0, 60).')';
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

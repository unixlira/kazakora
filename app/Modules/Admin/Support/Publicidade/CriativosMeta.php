<?php

namespace App\Modules\Admin\Support\Publicidade;

use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Facades\Cache;

/**
 * Tela Criativos (pedido do Lira em 2026-11-19): anúncio a anúncio, com as
 * métricas de vídeo, para KazaKora, FerraKora e GreenKora (infoprodutos).
 *
 * Contas: KazaKora e FerraKora pelo CampanhasMetaPorLoja (mesmo token);
 * GreenKora pela conta e token de config infoprodutos.meta (outro usuário).
 *
 * Contas (o que é cada número):
 *  - CPV = custo por visita à página = gasto ÷ landing_page_view.
 *  - Gancho = views de 3 s ÷ impressões.
 *  - Retenção 25/50/75/100% = vídeo assistido até o marco ÷ plays.
 *  - Tempo médio = video_avg_time_watched_actions; % = ÷ duração do vídeo.
 *  - CPA = gasto ÷ compras; ROAS = valor das compras ÷ gasto (o da Meta).
 * A linha de total soma o que se soma e recalcula as taxas das somas.
 *
 * Selo (um por linha, nesta ordem): pouco dado (< R$ 30) › vencedor (menor
 * CPA entre os que têm dado e compra) › clica e não compra (checkout sem
 * compra) › gancho fraco (CTR do link < 0,8% ou gancho < 70% da mediana dos
 * pares) › segura pouco (retenção de 50% ou tempo médio em % < 70% da
 * mediana dos pares). Pares = os outros vídeos da lista com dado suficiente.
 */
class CriativosMeta
{
    public const GREENKORA = 'greenkora';

    public const POUCO_DADO = 30.0;

    public const CTR_MINIMO = 0.8;

    /** Abaixo de 70% da mediana dos pares = fraco. */
    public const FRACAO_DA_MEDIANA = 0.7;

    public const SELOS = [
        'pouco_dado' => 'pouco dado',
        'vencedor' => 'vencedor',
        'clica_nao_compra' => 'clica e não compra',
        'gancho_fraco' => 'gancho fraco',
        'segura_pouco' => 'segura pouco',
    ];

    private const CACHE = 'meta-ads:criativos:v1:';

    public function __construct(
        private readonly CampanhasMetaPorLoja $lojas,
        private readonly MetaAdsService $meta,
    ) {}

    /** @return array<string, string> chave => nome */
    public function contas(): array
    {
        $contas = $this->lojas->lojas();
        if (filled(config('infoprodutos.meta.access_token')) && filled(config('infoprodutos.meta.ad_account_id'))) {
            $contas[self::GREENKORA] = 'GreenKora (infoprodutos)';
        }

        return $contas;
    }

    public function servico(string $conta): MetaAdsService
    {
        if ($conta === self::GREENKORA) {
            return $this->meta->comToken((string) config('infoprodutos.meta.access_token'), (string) config('infoprodutos.meta.ad_account_id'));
        }

        return $this->lojas->servicoDa($conta);
    }

    /**
     * @return array{erro: ?string, linhas: list<array<string, mixed>>, total: array<string, mixed>, campanhas: array<string, string>, moeda: ?string}
     */
    public function montar(string $conta, string $periodo, ?string $campanha = null): array
    {
        try {
            $bruto = Cache::remember(self::CACHE.md5($conta.'|'.$periodo), now()->addMinutes(5), function () use ($conta, $periodo) {
                $servico = $this->servico($conta);
                $linhas = $servico->criativos($periodo);
                $ids = array_values(array_filter(array_map(fn ($l) => $l['video_id'], $linhas)));
                try {
                    $duracoes = $ids !== [] ? $servico->duracaoDosVideos($ids) : [];
                } catch (MetaAdsException) {
                    $duracoes = []; // sem permissão no vídeo: fica só o tempo em segundos
                }

                try {
                    $moeda = $servico->moedaDaConta();
                } catch (MetaAdsException) {
                    $moeda = null;
                }

                return ['linhas' => $linhas, 'duracoes' => $duracoes, 'moeda' => $moeda];
            });
        } catch (MetaAdsException $erro) {
            return ['erro' => $erro->getMessage(), 'linhas' => [], 'total' => self::total([]), 'campanhas' => [], 'moeda' => null];
        }

        $campanhas = collect($bruto['linhas'])->mapWithKeys(fn ($l) => [$l['campanha_id'] => $l['campanha']])->sort()->all();
        $linhas = array_values(array_filter($bruto['linhas'], fn ($l) => $campanha === null || $l['campanha_id'] === $campanha));
        $linhas = self::analisar($linhas, $bruto['duracoes']);

        return ['erro' => null, 'linhas' => $linhas, 'total' => self::total($linhas), 'campanhas' => $campanhas, 'moeda' => $bruto['moeda'] ?? null];
    }

    /**
     * Calcula as taxas de cada linha e o selo.
     *
     * @param  list<array<string, mixed>>  $linhas  saída de MetaAdsService::criativos
     * @param  array<string, float>  $duracoes  video_id => segundos
     * @return list<array<string, mixed>>
     */
    public static function analisar(array $linhas, array $duracoes = []): array
    {
        $linhas = array_map(fn ($l) => self::calcular($l, $duracoes), $linhas);

        $comDado = array_filter($linhas, fn ($l) => $l['gasto'] >= self::POUCO_DADO);
        $videos = array_filter($comDado, fn ($l) => $l['e_video']);
        $medianaGancho = self::mediana(array_map(fn ($l) => $l['gancho'], $videos));
        $medianaP50 = self::mediana(array_map(fn ($l) => $l['retencao']['p50'], $videos));
        $medianaTempo = self::mediana(array_map(fn ($l) => $l['tempo_pct'], $videos));
        $comCompra = array_filter($comDado, fn ($l) => $l['compras'] > 0);
        $melhorCpa = $comCompra !== [] ? min(array_map(fn ($l) => $l['cpa'], $comCompra)) : null;

        $paresSuficientes = count($videos) >= 3;

        return array_map(function (array $l) use ($medianaGancho, $medianaP50, $medianaTempo, $melhorCpa, $paresSuficientes) {
            $l['selo'] = match (true) {
                $l['gasto'] < self::POUCO_DADO => 'pouco_dado',
                $melhorCpa !== null && $l['compras'] > 0 && $l['cpa'] === $melhorCpa => 'vencedor',
                $l['checkout'] > 0 && $l['compras'] === 0 => 'clica_nao_compra',
                ($l['ctr_link'] !== null && $l['ctr_link'] < self::CTR_MINIMO)
                    || ($paresSuficientes && $l['e_video'] && self::abaixo($l['gancho'], $medianaGancho)) => 'gancho_fraco',
                $paresSuficientes && $l['e_video'] && (self::abaixo($l['retencao']['p50'], $medianaP50) || self::abaixo($l['tempo_pct'], $medianaTempo)) => 'segura_pouco',
                default => null,
            };
            $l['selo_rotulo'] = $l['selo'] !== null ? self::SELOS[$l['selo']] : null;

            return $l;
        }, $linhas);
    }

    /** @param  array<string, mixed>  $l */
    private static function calcular(array $l, array $duracoes): array
    {
        $v = $l['video'] ?? [];
        $impressoes = (int) ($l['impressoes'] ?? 0);
        $plays = (int) ($v['plays'] ?? 0);
        $gasto = round((float) ($l['gasto'] ?? 0), 2);
        $paginas = (int) ($l['visualizacoes_pagina'] ?? 0);
        $compras = (int) ($l['compras'] ?? 0);
        $duracao = $l['video_id'] !== null ? ($duracoes[$l['video_id']] ?? null) : null;
        $tempo = $v['tempo_medio'] ?? null;
        $taxa = fn (int $n) => $plays > 0 ? round($n / $plays * 100, 1) : null;

        return $l + [
            'e_video' => $l['video_id'] !== null || $plays > 0,
            'cpv' => $paginas > 0 ? round($gasto / $paginas, 2) : null,
            'gancho' => $impressoes > 0 && $plays > 0 ? round(($v['views_3s'] ?? 0) / $impressoes * 100, 1) : null,
            'retencao' => ['p25' => $taxa((int) ($v['p25'] ?? 0)), 'p50' => $taxa((int) ($v['p50'] ?? 0)), 'p75' => $taxa((int) ($v['p75'] ?? 0)), 'p100' => $taxa((int) ($v['p100'] ?? 0))],
            'duracao' => $duracao,
            'tempo_pct' => $tempo !== null && $duracao ? round($tempo / $duracao * 100, 1) : null,
            'cpa' => $compras > 0 ? round($gasto / $compras, 2) : null,
        ];
    }

    /**
     * Total recalculado das somas (nunca média de médias).
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array<string, mixed>
     */
    public static function total(array $linhas): array
    {
        $s = fn (callable $f) => array_sum(array_map($f, $linhas));
        $gasto = round($s(fn ($l) => (float) $l['gasto']), 2);
        $impressoes = (int) $s(fn ($l) => (int) $l['impressoes']);
        $cliquesLink = (int) $s(fn ($l) => (int) ($l['cliques_link'] ?? 0));
        $paginas = (int) $s(fn ($l) => (int) ($l['visualizacoes_pagina'] ?? 0));
        $compras = (int) $s(fn ($l) => (int) $l['compras']);
        $vendas = round($s(fn ($l) => (float) ($l['vendas'] ?? 0)), 2);
        $videos = array_filter($linhas, fn ($l) => $l['e_video'] ?? false);
        $impVideo = (int) array_sum(array_map(fn ($l) => (int) $l['impressoes'], $videos));
        $v = fn (string $c) => (int) array_sum(array_map(fn ($l) => (int) ($l['video'][$c] ?? 0), $videos));
        $plays = $v('plays');
        $taxa = fn (int $n) => $plays > 0 ? round($n / $plays * 100, 1) : null;

        return [
            'gasto' => $gasto,
            'impressoes' => $impressoes,
            'cliques_link' => $cliquesLink,
            'ctr_link' => $impressoes > 0 ? round($cliquesLink / $impressoes * 100, 2) : null,
            'cpc_link' => $cliquesLink > 0 ? round($gasto / $cliquesLink, 2) : null,
            'visualizacoes_pagina' => $paginas,
            'cpv' => $paginas > 0 ? round($gasto / $paginas, 2) : null,
            'gancho' => $impVideo > 0 && $plays > 0 ? round($v('views_3s') / $impVideo * 100, 1) : null,
            'retencao' => ['p25' => $taxa($v('p25')), 'p50' => $taxa($v('p50')), 'p75' => $taxa($v('p75')), 'p100' => $taxa($v('p100'))],
            'video' => ['plays' => $plays, 'thruplay' => $v('thruplay'), 'views_3s' => $v('views_3s')],
            'carrinho' => (int) $s(fn ($l) => (int) ($l['carrinho'] ?? 0)),
            'checkout' => (int) $s(fn ($l) => (int) ($l['checkout'] ?? 0)),
            'compras' => $compras,
            'vendas' => $vendas,
            'cpa' => $compras > 0 ? round($gasto / $compras, 2) : null,
            'roas' => $gasto > 0 ? round($vendas / $gasto, 2) : null,
        ];
    }

    private static function abaixo(?float $valor, ?float $mediana): bool
    {
        return $valor !== null && $mediana !== null && $mediana > 0 && $valor < $mediana * self::FRACAO_DA_MEDIANA;
    }

    /** @param  array<int|string, ?float>  $valores */
    private static function mediana(array $valores): ?float
    {
        $v = array_values(array_filter($valores, fn ($x) => $x !== null));
        if ($v === []) {
            return null;
        }
        sort($v);
        $n = count($v);

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}

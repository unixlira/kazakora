<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Metadados das campanhas da Meta (pedido do Lira em 2026-10-27): tabela
 * de campanhas lida do NOSSO banco (gravado pelo meta-ads:gravar-dados);
 * clicando numa campanha, os conjuntos, os anúncios e a performance de
 * cada anúncio, inclusive dia a dia. Só leitura, não chama a Meta.
 */
class MetaAdsDadosController extends Controller
{
    private const FUSO = 'America/Sao_Paulo';

    /** Período da tela => rótulo (mesmos nomes da tela de campanhas). */
    public const PERIODOS = [
        'today' => 'Hoje',
        'yesterday' => 'Ontem',
        'last_7d' => 'Últimos 7 dias',
        'last_30d' => 'Últimos 30 dias',
        'this_month' => 'Mês atual',
        'maximum' => 'Desde o início',
    ];

    private const SOMAS = ['gasto', 'impressoes', 'cliques', 'cliques_link', 'visualizacoes_pagina', 'carrinho', 'checkout', 'compras', 'vendas'];

    public function index(Request $request): Response
    {
        [$periodo, $de, $ate] = $this->periodo($request);

        $metricas = $this->somar($de, $ate, 'campanha_meta_id');
        $conjuntos = DB::table('meta_ads_dados_conjuntos')->selectRaw('campanha_meta_id, count(*) n')->groupBy('campanha_meta_id')->pluck('n', 'campanha_meta_id');
        $anuncios = DB::table('meta_ads_dados_anuncios')->selectRaw('campanha_meta_id, count(*) n')->groupBy('campanha_meta_id')->pluck('n', 'campanha_meta_id');

        $campanhas = DB::table('meta_ads_dados_campanhas')->get()
            ->map(fn ($c) => [
                'meta_id' => $c->meta_id,
                'nome' => $c->nome,
                'status' => $c->status,
                'objetivo' => MetaAdsCampanha::OBJETIVOS[$c->objetivo ?? ''] ?? $c->objetivo,
                'orcamento_diario' => $c->orcamento_diario !== null ? (float) $c->orcamento_diario : null,
                'inicio' => $c->inicio,
                'fim' => $c->fim,
                'conjuntos' => (int) ($conjuntos[$c->meta_id] ?? 0),
                'anuncios' => (int) ($anuncios[$c->meta_id] ?? 0),
                'metricas' => $metricas[$c->meta_id] ?? $this->metricas(null),
            ])
            ->sortBy(fn ($c) => [$c['status'] === 'ACTIVE' ? 0 : 1, -$c['metricas']['gasto'], $c['nome']])
            ->values();

        return Inertia::render('Admin/MetaAds/Dados/Index', [
            'campanhas' => $campanhas,
            'totais' => $this->metricas((object) collect(self::SOMAS)->mapWithKeys(fn ($k) => [$k => $campanhas->sum("metricas.$k")])->all()),
            'periodo' => $periodo,
            'periodos' => self::PERIODOS,
            'intervalo' => ['de' => $de?->toDateString(), 'ate' => $ate->toDateString()],
            'atualizadoEm' => $this->atualizadoEm(),
        ]);
    }

    public function campanha(Request $request, string $metaId): Response
    {
        $campanha = DB::table('meta_ads_dados_campanhas')->where('meta_id', $metaId)->first();
        abort_if($campanha === null, 404);

        [$periodo, $de, $ate] = $this->periodo($request);
        $doConjunto = $this->somar($de, $ate, 'conjunto_meta_id', $metaId);
        $doAnuncio = $this->somar($de, $ate, 'anuncio_meta_id', $metaId);

        $anuncios = DB::table('meta_ads_dados_anuncios')->where('campanha_meta_id', $metaId)->get();
        $porConjunto = $anuncios->countBy('conjunto_meta_id');

        $conjuntos = DB::table('meta_ads_dados_conjuntos')->where('campanha_meta_id', $metaId)->get()
            ->map(fn ($s) => [
                'meta_id' => $s->meta_id,
                'nome' => $s->nome,
                'status' => $s->status,
                'orcamento_diario' => $s->orcamento_diario !== null ? (float) $s->orcamento_diario : null,
                'anuncios' => (int) ($porConjunto[$s->meta_id] ?? 0),
                'metricas' => $doConjunto[$s->meta_id] ?? $this->metricas(null),
            ])
            ->sortBy(fn ($s) => [-$s['metricas']['gasto'], $s['nome']])->values();
        $nomeDoConjunto = $conjuntos->pluck('nome', 'meta_id');

        // Dia a dia de cada anúncio no período (performance detalhada).
        $diario = DB::table('meta_ads_dados_desempenho')->where('campanha_meta_id', $metaId)
            ->when($de, fn ($q) => $q->where('data', '>=', $de->toDateString()))
            ->where('data', '<=', $ate->toDateString())
            ->orderByDesc('data')->get()
            ->groupBy('anuncio_meta_id')
            ->map(fn ($linhas) => $linhas->map(fn ($l) => ['data' => Carbon::parse($l->data)->toDateString()] + $this->metricas($l))->values());

        return Inertia::render('Admin/MetaAds/Dados/Campanha', [
            'campanha' => [
                'meta_id' => $campanha->meta_id,
                'nome' => $campanha->nome,
                'status' => $campanha->status,
                'objetivo' => MetaAdsCampanha::OBJETIVOS[$campanha->objetivo ?? ''] ?? $campanha->objetivo,
                'orcamento_diario' => $campanha->orcamento_diario !== null ? (float) $campanha->orcamento_diario : null,
                'orcamento_total' => $campanha->orcamento_total !== null ? (float) $campanha->orcamento_total : null,
                'inicio' => $campanha->inicio,
                'fim' => $campanha->fim,
                'metricas' => $this->somar($de, $ate, 'campanha_meta_id', $metaId)[$metaId] ?? $this->metricas(null),
            ],
            'conjuntos' => $conjuntos,
            'anuncios' => $anuncios->map(fn ($a) => [
                'meta_id' => $a->meta_id,
                'nome' => $a->nome,
                'status' => $a->status,
                'miniatura' => $a->miniatura,
                'conjunto_meta_id' => $a->conjunto_meta_id,
                'conjunto' => $nomeDoConjunto[$a->conjunto_meta_id] ?? '-',
                'metricas' => $doAnuncio[$a->meta_id] ?? $this->metricas(null),
            ])->sortBy(fn ($a) => [-$a['metricas']['gasto'], $a['nome']])->values(),
            'diario' => $diario,
            'periodo' => $periodo,
            'periodos' => self::PERIODOS,
            'intervalo' => ['de' => $de?->toDateString(), 'ate' => $ate->toDateString()],
            'atualizadoEm' => $this->atualizadoEm(),
        ]);
    }

    /** @return array{0: string, 1: ?Carbon, 2: Carbon} chave, início (null = desde o começo) e fim. */
    private function periodo(Request $request): array
    {
        $periodo = (string) $request->query('periodo', 'maximum');
        $periodo = array_key_exists($periodo, self::PERIODOS) ? $periodo : 'maximum';
        $hoje = now(self::FUSO)->startOfDay();

        return match ($periodo) {
            'today' => [$periodo, $hoje, $hoje],
            'yesterday' => [$periodo, $hoje->copy()->subDay(), $hoje->copy()->subDay()],
            // Como na Meta: os 7/30 dias anteriores, sem hoje.
            'last_7d' => [$periodo, $hoje->copy()->subDays(7), $hoje->copy()->subDay()],
            'last_30d' => [$periodo, $hoje->copy()->subDays(30), $hoje->copy()->subDay()],
            'this_month' => [$periodo, $hoje->copy()->startOfMonth(), $hoje],
            default => [$periodo, null, $hoje],
        };
    }

    /** @return array<string, array<string, mixed>> métricas por id do nível pedido. */
    private function somar(?Carbon $de, Carbon $ate, string $coluna, ?string $campanha = null): array
    {
        return DB::table('meta_ads_dados_desempenho')
            ->when($campanha, fn ($q) => $q->where('campanha_meta_id', $campanha))
            ->when($de, fn ($q) => $q->where('data', '>=', $de->toDateString()))
            ->where('data', '<=', $ate->toDateString())
            ->groupBy($coluna)
            ->selectRaw($coluna.' as id, '.implode(', ', array_map(fn ($k) => "sum($k) as $k", self::SOMAS)))
            ->get()
            ->mapWithKeys(fn ($l) => [$l->id => $this->metricas($l)])
            ->all();
    }

    /** Soma de um nível + taxas derivadas (CTR e CPC do clique no link, ROAS). */
    private function metricas(?object $l): array
    {
        $gasto = round((float) ($l->gasto ?? 0), 2);
        $impressoes = (int) ($l->impressoes ?? 0);
        $cliquesLink = (int) ($l->cliques_link ?? 0);
        $vendas = round((float) ($l->vendas ?? 0), 2);

        return [
            'gasto' => $gasto,
            'impressoes' => $impressoes,
            'cliques' => (int) ($l->cliques ?? 0),
            'cliques_link' => $cliquesLink,
            'ctr_link' => $impressoes > 0 ? round($cliquesLink / $impressoes * 100, 2) : null,
            'cpc_link' => $cliquesLink > 0 ? round($gasto / $cliquesLink, 2) : null,
            'cpm' => $impressoes > 0 ? round($gasto / $impressoes * 1000, 2) : null,
            'visualizacoes_pagina' => (int) ($l->visualizacoes_pagina ?? 0),
            'carrinho' => (int) ($l->carrinho ?? 0),
            'checkout' => (int) ($l->checkout ?? 0),
            'compras' => (int) ($l->compras ?? 0),
            'vendas' => $vendas,
            'roas' => $gasto > 0 ? round($vendas / $gasto, 2) : null,
        ];
    }

    private function atualizadoEm(): ?string
    {
        $quando = DB::table('meta_ads_dados_campanhas')->max('sincronizado_em');

        return $quando ? Carbon::parse($quando)->setTimezone(self::FUSO)->toIso8601String() : null;
    }
}

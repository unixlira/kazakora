<?php

namespace App\Modules\Infoprodutos\Support;

use App\Modules\Infoprodutos\Models\InfoprodutoCarrinho;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use App\Modules\Infoprodutos\Models\InfoprodutoVenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Funil dos infoprodutos (pedido do Lira em 2026-11-19), por produto e
 * período, a partir dos eventos anônimos (infoproduto_eventos) e das vendas
 * da Kiwify (infoproduto_vendas, infoproduto_carrinhos).
 *
 * Unidade = VISITA (sessão aleatória da aba), contada uma vez por passo:
 *   visitas › rolou 50% › play no vídeo › clicou em comprar › checkout
 *   iniciado (Kiwify: pix/boleto gerado, compra recusada, aprovada ou
 *   carrinho abandonado) › compra › order bump › upsell / downsell.
 * A taxa de cada passo é sobre o passo anterior; a geral, sobre as visitas.
 *
 * Eventos de teste (utm_source = teste-medicao) ficam fora.
 */
class FunilInfoprodutos
{
    /**
     * Mostrar as visitas de teste (utm_source=teste-medicao): o painel liga
     * com ?testes=1 para conferir a medição de ponta a ponta.
     */
    public static bool $comTestes = false;

    public const PERIODOS = ['hoje' => 'Hoje', '7d' => '7 dias', '30d' => '30 dias'];

    public const TODOS = 'todos';

    private const APROVADAS = [InfoprodutoVenda::APROVADA, InfoprodutoVenda::REEMBOLSADA, InfoprodutoVenda::CHARGEBACK];

    /**
     * Linha "Infoprodutos hoje" do app de vendas KazaKora: visitas (sessões
     * nas landings), vendas aprovadas e o valor em dólar.
     *
     * @return array{visitas: int, vendas: int, valor_usd: float}
     */
    public static function resumoDeHoje(): array
    {
        $de = now()->startOfDay();
        $vendas = InfoprodutoVenda::query()->where('status', InfoprodutoVenda::APROVADA)->where('aprovada_em', '>=', $de);

        return [
            'visitas' => (int) InfoprodutoEvento::query()->where('tipo', 'pageview')->where('teste', false)->where('pagina', 'landing')
                ->whereIn('produto', array_keys((array) config('infoprodutos.produtos')))
                ->where('created_at', '>=', $de)->distinct()->count('sessao'),
            'vendas' => (int) (clone $vendas)->where('tipo', 'principal')->count(),
            'valor_usd' => round((float) (clone $vendas)->where('moeda', 'USD')->sum('valor'), 2),
        ];
    }

    public static function periodo(?string $p): string
    {
        return array_key_exists((string) $p, self::PERIODOS) ? (string) $p : '7d';
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function intervalo(string $periodo, ?Carbon $agora = null): array
    {
        $agora ??= now();
        $dias = ['hoje' => 1, '7d' => 7, '30d' => 30][$periodo] ?? 7;

        return [$agora->copy()->startOfDay()->subDays($dias - 1), $agora->copy()];
    }

    /**
     * @param  array<string, array{gasto: float, nome: string}>  $gastoPorAnuncio  utm_content => gasto (Meta GreenKora)
     * @return array<string, mixed>
     */
    public function montar(string $produto, Carbon $de, Carbon $ate, array $gastoPorAnuncio = [], ?string $moedaMeta = null): array
    {
        $visitas = $this->pageviews($produto, $de, $ate);
        $sessoes = $visitas->pluck('sessao')->unique()->values();
        $eventos = $this->eventosDasSessoes($produto, $sessoes, $de, $ate);
        $flags = $this->flags($eventos);
        $vendas = $this->vendas($produto, $de, $ate);
        $principais = $vendas->where('tipo', 'principal');
        $aprovadas = $principais->filter(fn ($v) => in_array($v->status, self::APROVADAS, true) && $v->aprovada_em !== null);
        $carrinhos = $this->carrinhos($produto, $de, $ate);

        $nVisitas = $sessoes->count();
        $passos = [
            ['chave' => 'visitas', 'rotulo' => 'Visitas', 'n' => $nVisitas],
            ['chave' => 'rolou_50', 'rotulo' => 'Rolou 50%', 'n' => $flags->where('rolou_50', true)->count()],
            ['chave' => 'play', 'rotulo' => 'Deu play no vídeo', 'n' => $flags->where('play', true)->count()],
            ['chave' => 'comprar', 'rotulo' => 'Clicou em comprar', 'n' => $flags->where('comprar', true)->count()],
            ['chave' => 'checkout', 'rotulo' => 'Checkout iniciado (Kiwify)', 'n' => $principais->count() + $carrinhos->count()],
            ['chave' => 'compra', 'rotulo' => 'Compra', 'n' => $aprovadas->count()],
            ['chave' => 'order_bump', 'rotulo' => 'Order bump', 'n' => $this->aprovadasDoTipo($vendas, 'order_bump')],
            ['chave' => 'upsell', 'rotulo' => 'Upsell', 'n' => $this->aprovadasDoTipo($vendas, 'upsell')],
            ['chave' => 'downsell', 'rotulo' => 'Downsell', 'n' => $this->aprovadasDoTipo($vendas, 'downsell')],
        ];
        $passos = self::comTaxas($passos);

        $receita = $this->receita($vendas);

        return [
            'passos' => $passos,
            'receita' => $receita,
            'reembolsos' => $principais->whereIn('status', [InfoprodutoVenda::REEMBOLSADA, InfoprodutoVenda::CHARGEBACK])->count(),
            'video' => $this->video($flags),
            'botoes' => $this->botoes($eventos, $aprovadas),
            'anuncios' => $this->porAnuncio($visitas, $flags, $aprovadas, $vendas, $gastoPorAnuncio, $moedaMeta),
            'paises' => $this->porPais($visitas, $aprovadas),
            'semana_hora' => $this->semanaHora($visitas, $aprovadas),
            'tempo_medio' => $this->tempoMedio($eventos),
        ];
    }

    /**
     * Passos com taxa sobre o anterior e sobre as visitas. Bump, upsell e
     * downsell são sobre as compras (oferta para quem comprou).
     *
     * @param  list<array{chave: string, rotulo: string, n: int}>  $passos
     * @return list<array<string, mixed>>
     */
    public static function comTaxas(array $passos): array
    {
        $visitas = $passos[0]['n'] ?? 0;
        $compras = collect($passos)->firstWhere('chave', 'compra')['n'] ?? 0;
        $anterior = null;
        $saida = [];
        foreach ($passos as $p) {
            $base = in_array($p['chave'], ['order_bump', 'upsell', 'downsell'], true) ? $compras : $anterior;
            $saida[] = $p + [
                'taxa_passo' => $base !== null && $base > 0 ? round($p['n'] / $base * 100, 1) : null,
                'taxa_visitas' => $visitas > 0 ? round($p['n'] / $visitas * 100, 2) : null,
                'saiu' => $base !== null && ! in_array($p['chave'], ['order_bump', 'upsell', 'downsell'], true) ? max($base - $p['n'], 0) : null,
            ];
            if (! in_array($p['chave'], ['order_bump', 'upsell', 'downsell'], true)) {
                $anterior = $p['n'];
            }
        }

        return $saida;
    }

    private function pageviews(string $produto, Carbon $de, Carbon $ate): Collection
    {
        return InfoprodutoEvento::query()
            ->where('tipo', 'pageview')->when(! self::$comTestes, fn ($q) => $q->where('teste', false))->where('pagina', 'landing')
            ->when($produto === self::TODOS, fn ($q) => $q->whereIn('produto', array_keys((array) config('infoprodutos.produtos'))),
                fn ($q) => $q->where('produto', $produto))
            ->whereBetween('created_at', [$de, $ate])
            ->orderBy('id')
            ->get(['sessao', 'produto', 'pais', 'utm_source', 'utm_campaign', 'utm_content', 'created_at'])
            // Uma visita por sessão e produto (a primeira página aberta).
            ->unique(fn ($e) => $e->sessao.'|'.$e->produto)->values();
    }

    private function eventosDasSessoes(string $produto, Collection $sessoes, Carbon $de, Carbon $ate): Collection
    {
        if ($sessoes->isEmpty()) {
            return collect();
        }

        return $sessoes->chunk(500)->flatMap(fn ($lote) => InfoprodutoEvento::query()
            ->whereIn('sessao', $lote->all())
            ->whereIn('tipo', ['scroll', 'play', 'video', 'buy', 'preview', 'exit'])
            ->where('pagina', 'landing')
            ->when($produto !== self::TODOS, fn ($q) => $q->where('produto', $produto))
            ->where('created_at', '>=', $de)
            ->get(['sessao', 'tipo', 'valor']))->values();
    }

    /** Uma linha por sessão com os passos que ela fez. */
    private function flags(Collection $eventos): Collection
    {
        return $eventos->groupBy('sessao')->map(function (Collection $evs, string $sessao) {
            $scroll = $evs->where('tipo', 'scroll')->max(fn ($e) => (int) $e->valor) ?? 0;
            $video = $evs->where('tipo', 'video')->max(fn ($e) => (int) $e->valor) ?? 0;

            return [
                'sessao' => $sessao,
                'rolou_50' => $scroll >= 50,
                'play' => $evs->contains('tipo', 'play') || $video > 0,
                'video' => $video,
                'comprar' => $evs->contains('tipo', 'buy'),
                'previa' => $evs->contains('tipo', 'preview'),
            ];
        })->values();
    }

    private function vendas(string $produto, Carbon $de, Carbon $ate): Collection
    {
        return InfoprodutoVenda::query()
            ->when($produto !== self::TODOS, fn ($q) => $q->where('produto', $produto))
            ->where(fn ($q) => $q->whereBetween('aprovada_em', [$de, $ate])
                ->orWhere(fn ($q) => $q->whereNull('aprovada_em')->whereBetween('created_at', [$de, $ate])))
            ->get();
    }

    private function carrinhos(string $produto, Carbon $de, Carbon $ate): Collection
    {
        return InfoprodutoCarrinho::query()
            ->when($produto !== self::TODOS, fn ($q) => $q->where('produto', $produto))
            ->whereBetween('criado_em', [$de, $ate])->get();
    }

    private function aprovadasDoTipo(Collection $vendas, string $tipo): int
    {
        return $vendas->where('tipo', $tipo)->filter(fn ($v) => in_array($v->status, self::APROVADAS, true) && $v->aprovada_em !== null)->count();
    }

    /** Receita aprovada (sem reembolsadas) por moeda, e o líquido. */
    private function receita(Collection $vendas): array
    {
        $boas = $vendas->where('status', InfoprodutoVenda::APROVADA);

        return [
            'por_moeda' => $boas->groupBy(fn ($v) => $v->moeda ?? '?')->map(fn ($g) => round((float) $g->sum('valor'), 2))->all(),
            'liquido_por_moeda' => $boas->groupBy(fn ($v) => $v->moeda_liquido ?? '?')->map(fn ($g) => round((float) $g->sum('valor_liquido'), 2))->all(),
            'vendas' => $boas->count(),
        ];
    }

    private function video(Collection $flags): array
    {
        $plays = $flags->where('play', true)->count();
        $marcos = [];
        foreach ([25, 50, 75, 100] as $m) {
            $n = $flags->filter(fn ($f) => $f['video'] >= $m)->count();
            $marcos[] = ['marco' => $m, 'n' => $n, 'pct' => $plays > 0 ? round($n / $plays * 100, 1) : null];
        }

        return ['plays' => $plays, 'marcos' => $marcos, 'ate_o_fim' => $marcos[3]['pct']];
    }

    /**
     * Botão de compra: cliques por posição (evento buy) e compras pelo src
     * que o script põe no link do checkout (src = posição do botão).
     */
    private function botoes(Collection $eventos, Collection $aprovadas): array
    {
        $cliques = $eventos->where('tipo', 'buy')->groupBy('valor')->map(fn ($g) => $g->pluck('sessao')->unique()->count());
        $compras = $aprovadas->filter(fn ($v) => $v->src !== null)->groupBy('src')->map->count();

        return collect($cliques->keys())->merge($compras->keys())->unique()->map(fn ($pos) => [
            'posicao' => (string) $pos,
            'cliques' => (int) ($cliques[$pos] ?? 0),
            'compras' => (int) ($compras[$pos] ?? 0),
            'conversao' => ($cliques[$pos] ?? 0) > 0 ? round(($compras[$pos] ?? 0) / $cliques[$pos] * 100, 1) : null,
        ])->sortByDesc(fn ($b) => [$b['compras'], $b['cliques']])->values()->all();
    }

    /**
     * Por anúncio (utm_content). Gasto da Meta da GreenKora casado pelo
     * utm_content = id ou nome do anúncio. Receita só entra no ROAS quando
     * está na mesma moeda da conta de anúncios.
     */
    private function porAnuncio(Collection $visitas, Collection $flags, Collection $aprovadas, Collection $vendas, array $gasto, ?string $moedaMeta): array
    {
        $porSessao = $flags->keyBy('sessao');
        $linhas = [];
        foreach ($visitas->groupBy(fn ($v) => (string) $v->utm_content) as $conteudo => $grupo) {
            $linhas[$conteudo] = [
                'utm_content' => $conteudo,
                'utm_campaign' => (string) ($grupo->first()->utm_campaign ?? ''),
                'visitas' => $grupo->count(),
                'rolou_50' => $grupo->filter(fn ($v) => $porSessao[$v->sessao]['rolou_50'] ?? false)->count(),
                'comprar' => $grupo->filter(fn ($v) => $porSessao[$v->sessao]['comprar'] ?? false)->count(),
                'compras' => 0,
                'receita' => 0.0,
            ];
        }
        foreach ($aprovadas->groupBy(fn ($v) => (string) $v->utm_content) as $conteudo => $grupo) {
            $linhas[$conteudo] ??= ['utm_content' => $conteudo, 'utm_campaign' => (string) ($grupo->first()->utm_campaign ?? ''), 'visitas' => 0, 'rolou_50' => 0, 'comprar' => 0, 'compras' => 0, 'receita' => 0.0];
            $linhas[$conteudo]['compras'] = $grupo->count();
        }
        // Receita do anúncio = tudo o que a mesma venda trouxe (bump, upsell) com o mesmo utm_content.
        foreach ($vendas->where('status', InfoprodutoVenda::APROVADA)->groupBy(fn ($v) => (string) $v->utm_content) as $conteudo => $grupo) {
            if (isset($linhas[$conteudo])) {
                $linhas[$conteudo]['receita'] = round((float) $grupo->filter(fn ($v) => $moedaMeta === null || $v->moeda === $moedaMeta)->sum('valor'), 2);
            }
        }
        foreach ($gasto as $conteudo => $g) {
            $linhas[$conteudo] ??= ['utm_content' => $conteudo, 'utm_campaign' => '', 'visitas' => 0, 'rolou_50' => 0, 'comprar' => 0, 'compras' => 0, 'receita' => 0.0];
        }

        return collect($linhas)->map(function ($l) use ($gasto) {
            $g = $gasto[$l['utm_content']] ?? null;
            $valor = $g['gasto'] ?? null;

            return $l + [
                'anuncio' => $g['nome'] ?? null,
                'gasto' => $valor,
                'custo_visita' => $valor !== null && $l['visitas'] > 0 ? round($valor / $l['visitas'], 2) : null,
                'custo_compra' => $valor !== null && $l['compras'] > 0 ? round($valor / $l['compras'], 2) : null,
                'roas' => $valor ? round($l['receita'] / $valor, 2) : null,
                'conversao' => $l['visitas'] > 0 ? round($l['compras'] / $l['visitas'] * 100, 2) : null,
            ];
        })->sortByDesc(fn ($l) => [$l['gasto'] ?? 0, $l['visitas']])->values()->all();
    }

    private function porPais(Collection $visitas, Collection $aprovadas): array
    {
        $v = $visitas->groupBy(fn ($e) => $e->pais ?? '??')->map->count();
        $c = $aprovadas->groupBy(fn ($e) => $e->pais ?? '??')->map->count();

        return $v->keys()->merge($c->keys())->unique()->map(fn ($p) => [
            'pais' => $p,
            'visitas' => (int) ($v[$p] ?? 0),
            'compras' => (int) ($c[$p] ?? 0),
            'conversao' => ($v[$p] ?? 0) > 0 ? round(($c[$p] ?? 0) / $v[$p] * 100, 2) : null,
        ])->sortByDesc('visitas')->values()->all();
    }

    /** Visitas e compras por dia da semana (0 = domingo) e hora, no fuso da loja. */
    private function semanaHora(Collection $visitas, Collection $aprovadas): array
    {
        $grade = [];
        foreach ($visitas as $v) {
            $d = $v->created_at->copy()->setTimezone(config('app.timezone'));
            $grade[$d->dayOfWeek][$d->hour]['visitas'] = ($grade[$d->dayOfWeek][$d->hour]['visitas'] ?? 0) + 1;
        }
        foreach ($aprovadas as $c) {
            $d = $c->aprovada_em->copy()->setTimezone(config('app.timezone'));
            $grade[$d->dayOfWeek][$d->hour]['compras'] = ($grade[$d->dayOfWeek][$d->hour]['compras'] ?? 0) + 1;
        }

        $saida = [];
        foreach ($grade as $dia => $horas) {
            foreach ($horas as $hora => $n) {
                $saida[] = ['dia' => $dia, 'hora' => $hora, 'visitas' => $n['visitas'] ?? 0, 'compras' => $n['compras'] ?? 0];
            }
        }

        return $saida;
    }

    private function tempoMedio(Collection $eventos): ?int
    {
        $saidas = $eventos->where('tipo', 'exit')->groupBy('sessao')->map(fn ($g) => (int) $g->max(fn ($e) => (int) $e->valor));

        return $saidas->isNotEmpty() ? (int) round($saidas->avg()) : null;
    }
}

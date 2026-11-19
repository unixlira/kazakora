<?php

namespace App\Modules\Infoprodutos\Support;

use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tela Comportamento (pedido do Lira em 2026-11-19): mapa de cliques,
 * cliques mortos, curva de rolagem, sequência de passos mais comum e a
 * linha do tempo de cada visita (anônima), por página e aparelho.
 *
 * Página = produto (slug do infoproduto, kz/<slug> ou fk/<slug>) + tipo de
 * página (landing, oferta, oferta-2, produto). Aparelho pela largura da
 * janela no clique: celular < 768 px; computador ≥ 768 px.
 */
class Comportamento
{
    public const CELULAR = 'celular';

    public const COMPUTADOR = 'computador';

    public const LIMITE_CELULAR = 768;

    /** Páginas com visita no período (mais visitadas primeiro). */
    public function paginas(Carbon $de, Carbon $ate): array
    {
        return InfoprodutoEvento::query()
            ->where('tipo', 'pageview')->where('teste', false)
            ->whereBetween('created_at', [$de, $ate])
            ->selectRaw('produto, pagina, COUNT(DISTINCT sessao) as visitas')
            ->groupBy('produto', 'pagina')
            ->orderByDesc('visitas')
            ->limit(300)
            ->get()
            ->map(fn ($p) => [
                'produto' => $p->produto,
                'pagina' => $p->pagina,
                'loja' => self::loja($p->produto),
                'rotulo' => self::rotuloDaPagina($p->produto, $p->pagina),
                'visitas' => (int) $p->visitas,
            ])->all();
    }

    public static function loja(string $produto): string
    {
        return match (true) {
            str_starts_with($produto, 'kz/') => 'kazakora',
            str_starts_with($produto, 'fk/') => 'ferrakora',
            default => 'infoprodutos',
        };
    }

    public static function rotuloDaPagina(string $produto, string $pagina): string
    {
        $nome = config("infoprodutos.produtos.{$produto}") ?? (string) preg_replace('/^(kz|fk)\//', '', $produto);
        $sufixo = ['oferta' => ' · upsell', 'oferta-2' => ' · downsell'][$pagina] ?? '';

        return $nome.$sufixo;
    }

    /**
     * @return array<string, mixed>
     */
    public function montar(string $produto, string $pagina, string $aparelho, Carbon $de, Carbon $ate): array
    {
        $sessoes = $this->sessoes($produto, $pagina, $aparelho, $de, $ate);
        $ids = $sessoes->keys();
        $eventos = $this->eventos($produto, $pagina, $ids);
        $visitas = $ids->count();
        $cliques = $eventos->where('tipo', 'clk');

        return [
            'visitas' => $visitas,
            'pontos' => $this->pontos($cliques),
            'onde_clicam' => $this->ondeClicam($eventos, $visitas),
            'cliques_mortos' => $this->mortos($cliques, $visitas),
            'rolagem' => $this->rolagem($eventos, $visitas),
            'sequencias' => $this->sequencias($eventos),
            'tempo_medio' => ($t = $eventos->where('tipo', 'exit')->groupBy('sessao')->map(fn ($g) => (int) $g->max(fn ($e) => (int) $e->valor))) ->isNotEmpty() ? (int) round($t->avg()) : null,
            'visitas_recentes' => $sessoes->sortByDesc('inicio')->take(50)->values()->map(fn ($s) => [
                'sessao' => $s['sessao'],
                'inicio' => $s['inicio']->toIso8601String(),
                'pais' => $s['pais'],
                'origem' => $s['origem'],
                'acoes' => $eventos->where('sessao', $s['sessao'])->whereNotIn('tipo', ['pageview'])->count(),
                'segundos' => (int) ($eventos->where('sessao', $s['sessao'])->where('tipo', 'exit')->max(fn ($e) => (int) $e->valor) ?? 0),
            ])->all(),
        ];
    }

    /**
     * Linha do tempo de uma visita: cada ação com o texto legível e o tempo
     * desde a anterior. Só o código aleatório da sessão; nada pessoal.
     *
     * @return list<array<string, mixed>>
     */
    public function visita(string $sessao, string $produto): array
    {
        $eventos = InfoprodutoEvento::query()->where('sessao', $sessao)->where('produto', $produto)
            ->orderBy('created_at')->orderByRaw('COALESCE(ms, 0)')->orderBy('id')->limit(500)->get();

        $anterior = null;

        return $eventos->map(function ($e) use (&$anterior) {
            $ms = (int) ($e->ms ?? 0);
            $linha = [
                'tipo' => $e->tipo,
                'texto' => Rotulos::evento($e),
                'segundo' => round($ms / 1000, 1),
                'desde_anterior' => $anterior !== null ? round(max($ms - $anterior, 0) / 1000, 1) : null,
                'pagina' => $e->pagina,
                'y' => $e->y,
            ];
            $anterior = $ms;

            return $linha;
        })->all();
    }

    /** Sessões da página no período, com o aparelho da primeira largura vista. */
    private function sessoes(string $produto, string $pagina, string $aparelho, Carbon $de, Carbon $ate): Collection
    {
        $pv = InfoprodutoEvento::query()
            ->where('tipo', 'pageview')->where('teste', false)
            ->where('produto', $produto)->where('pagina', $pagina)
            ->whereBetween('created_at', [$de, $ate])
            ->orderBy('id')
            ->get(['sessao', 'dispositivo', 'pais', 'utm_source', 'utm_content', 'referrer', 'created_at'])
            ->unique('sessao');

        return $pv->filter(fn ($p) => $aparelho === self::CELULAR
            ? in_array($p->dispositivo, ['mobile', 'tablet', 'celular'], true)
            : ! in_array($p->dispositivo, ['mobile', 'tablet', 'celular'], true))
            ->mapWithKeys(fn ($p) => [$p->sessao => [
                'sessao' => $p->sessao,
                'inicio' => $p->created_at,
                'pais' => $p->pais,
                'origem' => $p->utm_source ? trim($p->utm_source.' '.($p->utm_content ?? '')) : ($p->referrer ?: 'direto'),
            ]]);
    }

    private function eventos(string $produto, string $pagina, Collection $sessoes): Collection
    {
        return $sessoes->chunk(500)->flatMap(fn ($lote) => InfoprodutoEvento::query()
            ->whereIn('sessao', $lote->all())->where('produto', $produto)->where('pagina', $pagina)
            ->orderBy('created_at')->orderByRaw('COALESCE(ms, 0)')->orderBy('id')
            ->get(['sessao', 'tipo', 'valor', 'ms', 'x', 'y', 'largura', 'alvo', 'secao', 'elemento', 'nome', 'rolagem_max']))->values();
    }

    /** Cliques agrupados em células (1% de largura × 10 px) para o mapa de calor. */
    private function pontos(Collection $cliques): array
    {
        return $cliques->filter(fn ($c) => $c->x !== null && $c->y !== null)
            ->groupBy(fn ($c) => $c->x.':'.intdiv((int) $c->y, 10))
            ->map(fn ($g) => ['x' => (int) $g->first()->x, 'y' => intdiv((int) $g->first()->y, 10) * 10 + 5, 'n' => $g->count(), 'morto' => $g->every(fn ($c) => $c->alvo === 'm')])
            ->sortByDesc('n')->take(4000)->values()->all();
    }

    /** Elemento legível + % das visitas que clicaram nele. */
    private function ondeClicam(Collection $eventos, int $visitas): array
    {
        return $eventos->filter(fn ($e) => in_array($e->tipo, ['clk', 'acao'], true))
            ->groupBy(fn ($e) => Rotulos::chave($e))
            ->map(fn ($g, $chave) => [
                'elemento' => Rotulos::evento($g->first()),
                'cliques' => $g->count(),
                'visitas' => $n = $g->pluck('sessao')->unique()->count(),
                'pct' => $visitas > 0 ? round($n / $visitas * 100, 1) : null,
            ])->sortByDesc('visitas')->take(30)->values()->all();
    }

    private function mortos(Collection $cliques, int $visitas): array
    {
        return $cliques->where('alvo', 'm')
            ->groupBy(fn ($c) => ($c->secao ?? '').'|'.($c->elemento ?? ''))
            ->map(fn ($g) => [
                'elemento' => Rotulos::evento($g->first()),
                'secao' => $g->first()->secao,
                'cliques' => $g->count(),
                'visitas' => $n = $g->pluck('sessao')->unique()->count(),
                'pct' => $visitas > 0 ? round($n / $visitas * 100, 1) : null,
                'y_medio' => (int) round($g->avg('y')),
            ])->sortByDesc('cliques')->take(20)->values()->all();
    }

    /**
     * % das visitas que chegaram a cada ponto da página (0 a 100, de 5 em 5),
     * pela rolagem máxima da saída ou, sem ela, pelo maior marco de rolagem.
     */
    private function rolagem(Collection $eventos, int $visitas): array
    {
        $maximo = $eventos->groupBy('sessao')->map(function ($g) {
            $sm = $g->where('tipo', 'exit')->max('rolagem_max');
            $marco = $g->where('tipo', 'scroll')->max(fn ($e) => (int) $e->valor);

            return max((int) $sm, (int) $marco);
        });
        $curva = [];
        for ($p = 0; $p <= 100; $p += 5) {
            $n = $p === 0 ? $visitas : $maximo->filter(fn ($m) => $m >= $p)->count();
            $curva[] = ['pct_pagina' => $p, 'pct_visitas' => $visitas > 0 ? round($n / $visitas * 100, 1) : null];
        }

        return $curva;
    }

    /** As 5 sequências de passos mais comuns (até 6 passos, sem repetir o mesmo passo seguido). */
    private function sequencias(Collection $eventos): array
    {
        return $eventos->groupBy('sessao')->map(function ($g) {
            $passos = ['entrou'];
            foreach ($g as $e) {
                $passo = Rotulos::passo($e);
                if ($passo !== null && end($passos) !== $passo) {
                    $passos[] = $passo;
                }
            }
            $passos = array_slice($passos, 0, 6);
            $passos[] = 'saiu';

            return implode(' → ', $passos);
        })->countBy()->sortDesc()->take(5)->map(fn ($n, $seq) => ['sequencia' => $seq, 'visitas' => $n])->values()->all();
    }
}

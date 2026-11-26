<?php

namespace App\Modules\Infoprodutos\Support;

use App\Modules\Catalog\Models\Product;
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
    /**
     * Mostrar as visitas de teste (utm_source=teste-medicao): o painel liga
     * com ?testes=1 para conferir a medição de ponta a ponta.
     */
    public static bool $comTestes = false;

    public const CELULAR = 'celular';

    public const COMPUTADOR = 'computador';

    public const LIMITE_CELULAR = 768;

    /** Páginas com visita no período (mais visitadas primeiro). */
    public function paginas(Carbon $de, Carbon $ate): array
    {
        $linhas = InfoprodutoEvento::query()
            ->where('tipo', 'pageview')->dePessoas(self::$comTestes)
            ->whereBetween('created_at', [$de, $ate])
            ->selectRaw('produto, pagina, COUNT(DISTINCT sessao) as visitas')
            ->groupBy('produto', 'pagina')
            ->orderByDesc('visitas')
            ->limit(300)
            ->get();

        // Nome legível do produto da KazaKora (o slug é enorme).
        $slugs = $linhas->pluck('produto')->filter(fn ($p) => str_starts_with($p, 'kz/'))->map(fn ($p) => substr($p, 3))->unique()->values();
        $nomes = $slugs->isEmpty() ? collect() : Product::query()->withTrashed()->whereIn('slug', $slugs->all())->pluck('name', 'slug');

        return $linhas->map(fn ($p) => [
            'produto' => $p->produto,
            'pagina' => $p->pagina,
            'loja' => self::loja($p->produto),
            'rotulo' => self::rotuloDaPagina($p->produto, $p->pagina, $nomes[substr($p->produto, 3)] ?? null),
            'visitas' => (int) $p->visitas,
        ])->all();
    }

    public static function loja(string $produto): string
    {
        return PaginasDoSite::loja($produto);
    }

    public static function rotuloDaPagina(string $produto, string $pagina, ?string $nomeDoCatalogo = null): string
    {
        if (PaginasDoSite::paginaDeLoja($produto)) {
            return PaginasDoSite::nomeDaPagina($produto);
        }
        $nome = config("infoprodutos.produtos.{$produto}") ?? $nomeDoCatalogo
            ?? ucfirst(str_replace('-', ' ', (string) preg_replace('/^(kz|fk)\//', '', $produto)));
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
            'toques' => $this->toques($cliques, $visitas),
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
        $eventos = InfoprodutoEvento::query()->where('sessao', $sessao)->whereIn('produto', ChaveAtualDaPagina::todas($produto))
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
                'x' => self::temPosicao($e) ? $e->x : null,
                'y' => self::temPosicao($e) ? $e->y : null,
                'valor' => $e->tipo === 'scroll' ? (int) $e->valor : null,
            ];
            $anterior = $ms;

            return $linha;
        })->all();
    }

    /**
     * Clique de pessoa com posição. x = 0 e y = 0 juntos é clique sem
     * coordenada do script antigo (teclado, clique disparado por código): 80
     * dos 108 cliques do Jarvis em 2026-11-19 vieram assim (o robô de revisão
     * do Meta chamando el.click() em todo link) e empilhavam no canto do mapa.
     * O script novo manda x/y null nesse caso, ou o centro do alvo com
     * origem 'p' (programa/robô), que também fica fora do mapa e do
     * Reproduzir; o clique de teclado (origem 't') entra, no centro do alvo.
     */
    public static function temPosicao(object $e): bool
    {
        return $e->x !== null && $e->y !== null && ((int) $e->x !== 0 || (int) $e->y !== 0)
            && ($e->origem ?? null) !== 'p';
    }

    /** Sessões da página no período, com o aparelho da primeira largura vista. */
    private function sessoes(string $produto, string $pagina, string $aparelho, Carbon $de, Carbon $ate): Collection
    {
        $pv = InfoprodutoEvento::query()
            ->where('tipo', 'pageview')->dePessoas(self::$comTestes)
            ->whereIn('produto', ChaveAtualDaPagina::todas($produto))->where('pagina', $pagina)
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
            ->whereIn('sessao', $lote->all())->whereIn('produto', ChaveAtualDaPagina::todas($produto))->where('pagina', $pagina)
            ->orderBy('created_at')->orderByRaw('COALESCE(ms, 0)')->orderBy('id')
            ->get(['sessao', 'tipo', 'valor', 'ms', 'x', 'y', 'largura', 'alvo', 'secao', 'elemento', 'nome', 'origem', 'rolagem_max']))->values();
    }

    /** Cliques agrupados em células (1% de largura × 10 px) para o mapa de calor. */
    private function pontos(Collection $cliques): array
    {
        return $cliques->filter(fn ($c) => self::temPosicao($c))
            ->groupBy(fn ($c) => $c->x.':'.intdiv((int) $c->y, 10))
            ->map(fn ($g) => ['x' => (int) $g->first()->x, 'y' => intdiv((int) $g->first()->y, 10) * 10 + 5, 'n' => $g->count(), 'morto' => $g->every(fn ($c) => $c->alvo === 'm')])
            ->sortByDesc('n')->take(4000)->values()->all();
    }

    /**
     * Toques mais comuns para o "Reproduzir" (até 10): o ponto do meio
     * (mediana) de cada elemento clicado, o texto e quantas visitas tocaram.
     * Em ordem de posição na página (de cima para baixo), como alguém rolando.
     */
    private function toques(Collection $cliques, int $visitas): array
    {
        $mediana = function (Collection $v): int {
            $v = $v->sort()->values();

            return (int) $v[intdiv($v->count(), 2)];
        };

        return $cliques->filter(fn ($c) => self::temPosicao($c))
            ->groupBy(fn ($c) => Rotulos::chave($c))
            ->map(fn ($g) => [
                'texto' => Rotulos::evento($g->first()),
                'x' => $mediana($g->pluck('x')),
                'y' => $mediana($g->pluck('y')),
                'visitas' => $n = $g->pluck('sessao')->unique()->count(),
                'pct' => $visitas > 0 ? round($n / $visitas * 100, 1) : null,
            ])->sortByDesc('visitas')->take(10)->sortBy('y')->values()->all();
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

<?php

namespace App\Services\Meta;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Card de crescimento da tela Meta ADS (pedido do Lira em 2026-10-27): do
 * dia 1º ao último dia do mês, o dia a dia de cada campanha ativa, lido do
 * nosso banco (meta_ads_dados_*, gravado pelo meta-ads:gravar-dados). Diz
 * se a campanha principal está subindo ou caindo e dá um botão por
 * campanha ativa, a mais nova primeiro (à esquerda).
 */
class MetaAdsCrescimento
{
    private const FUSO = 'America/Sao_Paulo';

    /** Métrica que decide "subindo" ou "caindo". */
    public const METRICA = 'vendas';

    /** Séries mandadas para o gráfico. */
    private const SERIES = ['gasto', 'compras', 'vendas'];

    public function montar(?Carbon $hoje = null): array
    {
        $hoje = ($hoje ?? now(self::FUSO))->copy()->setTimezone(self::FUSO)->startOfDay();
        $inicio = $hoje->copy()->startOfMonth();
        $fim = $hoje->copy()->endOfMonth()->startOfDay();

        $dias = [];
        for ($d = $inicio->copy(); $d->lte($fim); $d->addDay()) {
            $dias[] = $d->toDateString();
        }

        $ativas = DB::table('meta_ads_dados_campanhas')->where('status', 'ACTIVE')->get();

        $linhas = DB::table('meta_ads_dados_desempenho')
            ->whereIn('campanha_meta_id', $ativas->pluck('meta_id'))
            ->whereBetween('data', [$inicio->toDateString(), $hoje->toDateString()])
            ->groupBy('campanha_meta_id', 'data')
            ->selectRaw('campanha_meta_id, data, sum(gasto) gasto, sum(compras) compras, sum(vendas) vendas')
            ->get()
            ->groupBy('campanha_meta_id');

        // Dia que ainda não chegou fica vazio (null), não zero.
        $ateHoje = array_flip(array_filter($dias, fn ($d) => $d <= $hoje->toDateString()));

        $campanhas = $ativas->map(function ($c) use ($linhas, $dias, $ateHoje) {
            $porDia = ($linhas[$c->meta_id] ?? collect())->keyBy(fn ($l) => Carbon::parse($l->data)->toDateString());
            $serie = [];
            foreach (self::SERIES as $k) {
                $serie[$k] = array_map(fn ($d) => isset($ateHoje[$d])
                    ? ($k === 'compras' ? (int) ($porDia[$d]->compras ?? 0) : round((float) ($porDia[$d]->$k ?? 0), 2))
                    : null, $dias);
            }

            return [
                'meta_id' => $c->meta_id,
                'nome' => $c->nome,
                'criada_em' => $c->criada_na_meta ?? $c->inicio,
                'serie' => $serie,
                'totais' => collect(self::SERIES)->mapWithKeys(fn ($k) => [$k => round(array_sum(array_filter($serie[$k], fn ($v) => $v !== null)), 2)])->all(),
                'tendencia' => $this->tendencia($serie[self::METRICA], $dias, $porDia->keys()->min()),
            ];
        });

        // Principal: a que mais gastou no mês.
        $principal = $campanhas->sortByDesc(fn ($c) => $c['totais']['gasto'])->first();

        return [
            'mes' => $inicio->toDateString(),
            'dias' => $dias,
            'hoje' => $hoje->toDateString(),
            'metrica' => self::METRICA,
            'principal' => $principal['meta_id'] ?? null,
            // Ordem decrescente (Lira 27/10): a campanha mais nova à esquerda.
            'campanhas' => $campanhas->sortByDesc(fn ($c) => (string) $c['criada_em'])->values()->all(),
            'atualizado_em' => ($q = DB::table('meta_ads_dados_campanhas')->max('sincronizado_em'))
                ? Carbon::parse($q)->setTimezone(self::FUSO)->toIso8601String() : null,
        ];
    }

    /**
     * Reta dos mínimos quadrados sobre os dias já fechados do mês (hoje
     * ainda está pela metade e puxaria a reta para baixo), a partir do
     * primeiro dia com dado da campanha. Inclinação > 0 = subindo.
     */
    private function tendencia(array $valores, array $dias, ?string $primeiroDia): array
    {
        $pontos = [];
        $fechados = count(array_filter($valores, fn ($v) => $v !== null)) - 1;
        foreach ($valores as $i => $v) {
            if ($v === null || $i >= $fechados || ($primeiroDia !== null && $dias[$i] < $primeiroDia)) {
                continue;
            }
            $pontos[] = [$i, $v];
        }

        if (count($pontos) < 3) {
            return ['direcao' => 'cedo', 'dias' => count($pontos), 'inclinacao' => null, 'reta' => null];
        }

        $n = count($pontos);
        $mx = array_sum(array_column($pontos, 0)) / $n;
        $my = array_sum(array_column($pontos, 1)) / $n;
        $num = $den = 0.0;
        foreach ($pontos as [$x, $y]) {
            $num += ($x - $mx) * ($y - $my);
            $den += ($x - $mx) ** 2;
        }
        $inclinacao = $den > 0 ? $num / $den : 0.0;

        return [
            'direcao' => $inclinacao > 0 ? 'subindo' : ($inclinacao < 0 ? 'caindo' : 'estavel'),
            'dias' => $n,
            // Quanto a reta sobe ou desce por dia, na unidade da métrica.
            'inclinacao' => round($inclinacao, 2),
            // Reta desenhada no gráfico: índice do dia => valor da reta.
            'reta' => [
                'de' => $pontos[0][0],
                'ate' => $pontos[$n - 1][0],
                'inicio' => round($my + $inclinacao * ($pontos[0][0] - $mx), 2),
                'fim' => round($my + $inclinacao * ($pontos[$n - 1][0] - $mx), 2),
            ],
        ];
    }
}

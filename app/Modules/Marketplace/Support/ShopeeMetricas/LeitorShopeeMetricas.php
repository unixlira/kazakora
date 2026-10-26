<?php

namespace App\Modules\Marketplace\Support\ShopeeMetricas;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lê shopee_metricas para um período escolhido na tela (Análise Profunda
 * de Conta, 2026-10-26). A coleta da Shopee vem em períodos fechados (7 e
 * 30 dias) e dia a dia; a ordem de preferência é:
 *
 *   1. coleta do período exato (o número que a própria Shopee mostrou);
 *   2. soma dos dias, quando todos os dias do período foram coletados;
 *   3. a coleta de período mais próxima (a tela avisa qual período é);
 *   4. os dias que houver (a tela avisa que é parcial).
 *
 * Nada aqui inventa número: métrica que não veio fica fora (a tela mostra
 * "s/d"), nunca zero.
 */
class LeitorShopeeMetricas
{
    /** Taxas que, na soma de dias, viram média ponderada pelos visitantes. */
    private const SOMAVEIS_POR_CONTA = ['ctr'];

    /**
     * Métricas de um escopo por anúncio (item_id, sem variação) no período.
     *
     * @return array{fonte: ?array{tipo: string, inicio: string, fim: string, coletado_em: ?string, dias: int}, itens: array<string, array{nome: ?string, m: array<string, float>}>}
     */
    public function porItem(string $escopo, Carbon $desde, Carbon $ate): array
    {
        $fonte = $this->resolver($escopo, $desde, $ate);
        if (! $fonte) {
            return ['fonte' => null, 'itens' => []];
        }

        $base = DB::table('shopee_metricas')->where('escopo', $escopo)->whereNotNull('item_id')->whereNull('model_id');
        if ($fonte['tipo'] === 'periodo' || $fonte['tipo'] === 'aproximado') {
            $linhas = $base->whereNull('data')
                ->where('periodo_inicio', $fonte['inicio'])->where('periodo_fim', $fonte['fim'])
                ->get(['item_id', 'nome', 'metrica', 'valor']);
            $itens = [];
            foreach ($linhas as $l) {
                $itens[$l->item_id]['nome'] ??= $l->nome;
                $itens[$l->item_id]['m'][$l->metrica] = (float) $l->valor;
            }

            return ['fonte' => $fonte, 'itens' => $itens];
        }

        $linhas = $base->whereNotNull('data')->whereBetween('data', [$fonte['inicio'], $fonte['fim']])
            ->get(['item_id', 'nome', 'data', 'metrica', 'valor', 'unidade']);

        return ['fonte' => $fonte, 'itens' => $this->somarDias($linhas)];
    }

    /**
     * Soma dia a dia: contagem e reais somam; porcentagem vira média
     * ponderada pelos visitantes do dia (CTR é refeito por cliques ÷
     * impressões, a mesma conta da Shopee).
     */
    private function somarDias($linhas): array
    {
        $dias = [];
        foreach ($linhas as $l) {
            $dias[$l->item_id]['nome'] ??= $l->nome;
            $dias[$l->item_id]['d'][$l->data][$l->metrica] = [(float) $l->valor, $l->unidade];
        }

        $itens = [];
        foreach ($dias as $item => $info) {
            $soma = [];
            $peso = [];
            foreach ($info['d'] as $metricas) {
                $visitantes = $metricas['visitantes'][0] ?? 0.0;
                foreach ($metricas as $metrica => [$valor, $unidade]) {
                    if ($unidade === '%') {
                        $peso[$metrica] = ($peso[$metrica] ?? [0.0, 0.0]);
                        $peso[$metrica][0] += $valor * $visitantes;
                        $peso[$metrica][1] += $visitantes;
                    } else {
                        $soma[$metrica] = ($soma[$metrica] ?? 0.0) + $valor;
                    }
                }
            }
            foreach ($peso as $metrica => [$numerador, $visitantes]) {
                if (in_array($metrica, self::SOMAVEIS_POR_CONTA, true)) {
                    continue;
                }
                if ($visitantes > 0) {
                    $soma[$metrica] = round($numerador / $visitantes, 2);
                }
            }
            if (isset($soma['impressoes'], $soma['cliques']) && $soma['impressoes'] > 0) {
                $soma['ctr'] = round($soma['cliques'] / $soma['impressoes'] * 100, 2);
            }
            $itens[$item] = ['nome' => $info['nome'], 'm' => $soma];
        }

        return $itens;
    }

    /**
     * Qual coleta usar para o período (ver o comentário da classe).
     *
     * @return ?array{tipo: string, inicio: string, fim: string, coletado_em: ?string, dias: int}
     */
    public function resolver(string $escopo, Carbon $desde, Carbon $ate): ?array
    {
        $de = $desde->toDateString();
        $a = $ate->toDateString();
        $diasPedidos = $desde->copy()->startOfDay()->diffInDays($ate->copy()->startOfDay()) + 1;

        $exato = DB::table('shopee_metricas')->where('escopo', $escopo)->whereNull('data')
            ->where('periodo_inicio', $de)->where('periodo_fim', $a)
            ->selectRaw('COUNT(*) as linhas, MAX(coletado_em) as coletado_em')->first();
        if ($exato && (int) $exato->linhas > 0) {
            return ['tipo' => 'periodo', 'inicio' => $de, 'fim' => $a, 'coletado_em' => $exato->coletado_em, 'dias' => (int) $diasPedidos];
        }

        $diarios = DB::table('shopee_metricas')->where('escopo', $escopo)->whereNotNull('data')->whereBetween('data', [$de, $a])
            ->selectRaw('COUNT(DISTINCT data) as dias, MIN(data) as inicio, MAX(data) as fim, MAX(coletado_em) as coletado_em')->first();
        if ($diarios && (int) $diarios->dias >= $diasPedidos) {
            return ['tipo' => 'diario', 'inicio' => $de, 'fim' => $a, 'coletado_em' => $diarios->coletado_em, 'dias' => (int) $diasPedidos];
        }

        $periodos = DB::table('shopee_metricas')->where('escopo', $escopo)->whereNull('data')->whereNotNull('periodo_inicio')
            ->groupBy('periodo_inicio', 'periodo_fim')
            ->selectRaw('periodo_inicio, periodo_fim, MAX(coletado_em) as coletado_em')->get();
        if ($periodos->isNotEmpty()) {
            $melhor = $periodos->sortBy(fn ($p) => abs(Carbon::parse($p->periodo_inicio)->diffInDays($desde, false))
                + abs(Carbon::parse($p->periodo_fim)->diffInDays($ate, false)))->first();
            $inicio = Carbon::parse($melhor->periodo_inicio);
            $fim = Carbon::parse($melhor->periodo_fim);

            return ['tipo' => 'aproximado', 'inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString(),
                'coletado_em' => $melhor->coletado_em, 'dias' => (int) $inicio->diffInDays($fim) + 1];
        }

        if ($diarios && (int) $diarios->dias > 0) {
            return ['tipo' => 'parcial', 'inicio' => (string) $diarios->inicio, 'fim' => (string) $diarios->fim,
                'coletado_em' => $diarios->coletado_em, 'dias' => (int) $diarios->dias];
        }

        return null;
    }

    /**
     * Escopo `hora` (visão geral de vendas, loja inteira): soma por hora do
     * dia e por dia, nos dias do período que foram coletados.
     *
     * @return array{dias_coletados: int, inicio: ?string, fim: ?string, coletado_em: ?string, por_hora: array<int, array<string, float>>, por_dia: list<array<string, mixed>>}
     */
    public function porHora(Carbon $desde, Carbon $ate): array
    {
        $linhas = DB::table('shopee_metricas')->where('escopo', 'hora')->whereNotNull('data')->whereNotNull('hora')
            ->whereBetween('data', [$desde->toDateString(), $ate->toDateString()])
            ->get(['data', 'hora', 'metrica', 'valor', 'coletado_em']);

        $porHora = [];
        $porDia = [];
        foreach ($linhas as $l) {
            $data = substr((string) $l->data, 0, 10);
            $porHora[(int) $l->hora][$l->metrica] = ($porHora[(int) $l->hora][$l->metrica] ?? 0.0) + (float) $l->valor;
            $porDia[$data][$l->metrica] = ($porDia[$data][$l->metrica] ?? 0.0) + (float) $l->valor;
        }
        ksort($porHora);
        ksort($porDia);

        return [
            'dias_coletados' => count($porDia),
            'inicio' => array_key_first($porDia),
            'fim' => array_key_last($porDia),
            'coletado_em' => $linhas->max('coletado_em'),
            'por_hora' => $porHora,
            'por_dia' => array_map(fn ($data, $m) => ['data' => $data] + array_map(fn ($v) => round($v, 2), $m), array_keys($porDia), $porDia),
        ];
    }

    /** Escopo `loja` com uma linha por métrica (Saúde da Conta e afins), da coleta mais nova. */
    public function daLoja(): array
    {
        return DB::table('shopee_metricas')->where('escopo', 'loja')->whereNull('item_id')
            ->orderByDesc('coletado_em')->get(['metrica', 'rotulo', 'valor', 'unidade', 'texto_original', 'tela', 'data', 'periodo_inicio', 'periodo_fim', 'coletado_em'])
            ->unique('metrica')->values()->map(fn ($l) => (array) $l)->all();
    }

    /** Última coleta de cada fonte (tela da Shopee e ADS da coleta diária). */
    public function ultimaColeta(): array
    {
        return DB::table('shopee_metricas')->groupBy('fonte')->selectRaw('fonte, MAX(coletado_em) as coletado_em')
            ->pluck('coletado_em', 'fonte')->all();
    }
}

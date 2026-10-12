<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Curva ABC pelo faturamento — uma só para o painel inicial e para o menu
 * Oportunidades (Mercado Livre / Shopee), pedido do Lira 2026-10-12.
 *
 * Do produto que mais fatura para o que menos, somando: A até 80% do
 * faturamento, B até 95%, o resto é C. O produto que cruza o limite fica
 * na faixa de cima (regra do painel desde 2026-10-06).
 *
 * Variações (cores) do mesmo produto viram UMA linha: pelo produto pai
 * (products.parent_product_id) ou, quando o cadastro não tem pai, pelo
 * nome sem a cor no fim ("Fonte Pet Branco" + "Fonte Pet Preto").
 *
 * Valores (receita, custo, taxa, frete, lucro) vêm de
 * ContributionMargin::porItem(): a conta oficial, sem conta paralela.
 */
class CurvaAbc
{
    public const LIMITE_A = 80;

    public const LIMITE_B = 95;

    public function __construct(private readonly ContributionMargin $margem) {}

    /**
     * Curva do período [desde, ate) com tendência contra o período anterior
     * de mesmo tamanho.
     *
     * @return array{total: float, unidades: int, lucro_produtos: float, ads: float, flex: float, bonificacoes: float, lucro: float, produtos: list<array>, classes: array<string, array{produtos: int, receita: float, lucro: float}>, anterior: array{desde: string, ate: string, total: float}}
     */
    public function analisar(Carbon $desde, Carbon $ate, ?string $canal = null): array
    {
        $atual = $this->margem->porItem($desde, $ate, $canal);
        $produtos = $this->agrupar($atual['linhas']);

        $dias = (int) $desde->diffInDays($ate);
        $antesDesde = $desde->copy()->subDays($dias);
        $anteriores = $this->agrupar($this->margem->porItem($antesDesde, $desde->copy(), $canal)['linhas'])->keyBy('chave');

        $curva = $this->classificar($produtos)->map(function (array $produto) use ($anteriores) {
            $antes = (float) ($anteriores->get($produto['chave'])['receita'] ?? 0);
            $produto['receita_anterior'] = round($antes, 2);
            $produto['tendencia'] = $antes > 0 ? round(($produto['receita'] - $antes) / $antes * 100, 1) : null;

            return $produto;
        });

        $lucroProdutos = round((float) $curva->sum('lucro'), 2);
        $canalCustos = $atual['canal'];

        return [
            'total' => round((float) $curva->sum('receita'), 2),
            'unidades' => (int) $curva->sum('unidades'),
            'lucro_produtos' => $lucroProdutos,
            'ads' => $canalCustos['ads'],
            'flex' => $canalCustos['flex'],
            'bonificacoes' => $canalCustos['bonificacoes'],
            'lucro' => round($lucroProdutos - $canalCustos['ads'] - $canalCustos['flex'] + $canalCustos['bonificacoes'], 2),
            'produtos' => $curva->values()->all(),
            'classes' => collect(['A', 'B', 'C'])->mapWithKeys(fn ($classe) => [$classe => [
                'produtos' => $curva->where('classe', $classe)->count(),
                'receita' => round((float) $curva->where('classe', $classe)->sum('receita'), 2),
                'lucro' => round((float) $curva->where('classe', $classe)->sum('lucro'), 2),
            ]])->all(),
            'anterior' => [
                'desde' => $antesDesde->toDateString(),
                'ate' => $desde->copy()->subDay()->toDateString(),
                'total' => round((float) $anteriores->sum('receita'), 2),
            ],
        ];
    }

    /**
     * Ordena por faturamento e marca classe, % e % acumulado. Produto sem
     * faturamento (brinde, R$ 0) fica de fora da curva.
     *
     * @param  Collection<int, array>  $produtos
     * @return Collection<int, array>
     */
    public function classificar(Collection $produtos): Collection
    {
        $produtos = $produtos->where('receita', '>', 0)->sortByDesc('receita')->values();
        $total = (float) $produtos->sum('receita');
        $acumulado = 0.0;

        return $produtos->map(function (array $produto) use ($total, &$acumulado) {
            $antes = $total > 0 ? $acumulado / $total * 100 : 100;
            $acumulado += $produto['receita'];
            $produto['classe'] = $antes < self::LIMITE_A ? 'A' : ($antes < self::LIMITE_B ? 'B' : 'C');
            $produto['participacao'] = $total > 0 ? round($produto['receita'] / $total * 100, 1) : 0.0;
            $produto['acumulado'] = $total > 0 ? round($acumulado / $total * 100, 1) : 0.0;

            return $produto;
        });
    }

    /**
     * Junta as linhas de pedido por produto (variações juntas).
     *
     * @param  list<array>  $linhas
     * @return Collection<int, array>
     */
    public function agrupar(array $linhas): Collection
    {
        $ids = collect($linhas)->pluck('product_id')->filter()->unique()->values();
        $cadastro = $ids->isEmpty() ? collect() : DB::table('products')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'color', 'parent_product_id'])
            ->keyBy('id');
        $pais = $cadastro->pluck('parent_product_id')->filter()->unique();
        $nomesPais = $pais->isEmpty() ? collect() : DB::table('products')->whereIn('id', $pais)->pluck('name', 'id');
        $temFilhos = $ids->isEmpty() ? collect() : DB::table('products')->whereIn('parent_product_id', $ids)->distinct()->pluck('parent_product_id')->flip();

        return collect($linhas)
            ->map(function (array $linha) use ($cadastro, $nomesPais, $temFilhos) {
                $produto = $linha['product_id'] ? $cadastro->get($linha['product_id']) : null;
                $cor = $produto?->color ? trim((string) $produto->color) : null;

                if ($produto?->parent_product_id) {
                    $chave = 'p'.$produto->parent_product_id;
                    $nome = (string) ($nomesPais[$produto->parent_product_id] ?? $produto->name);
                    $link = (int) $produto->parent_product_id;
                } elseif ($produto && $temFilhos->has($produto->id)) {
                    $chave = 'p'.$produto->id;
                    $nome = (string) $produto->name;
                    $link = (int) $produto->id;
                } else {
                    $nome = self::semCor((string) ($produto->name ?? $linha['product_name']), $cor);
                    $chave = 'n'.mb_strtolower($nome);
                    $link = $produto ? (int) $produto->id : null;
                }

                return $linha + ['chave' => $chave, 'nome' => $nome !== '' ? $nome : '(sem nome)', 'link' => $link, 'cor' => $cor];
            })
            ->groupBy('chave')
            ->map(function (Collection $grupo, string $chave) {
                $cores = $grupo->pluck('cor')->filter()->unique()->values();

                return [
                    'chave' => $chave,
                    'id' => $grupo->pluck('link')->filter()->first(),
                    'nome' => $grupo->first()['nome'],
                    'variacoes' => $cores->all(),
                    'pedidos' => $grupo->pluck('order_id')->unique()->count(),
                    'unidades' => (int) $grupo->sum('quantidade'),
                    'receita' => round((float) $grupo->sum('receita'), 2),
                    'custo_produtos' => round((float) $grupo->sum('custo_produtos'), 2),
                    'taxas' => round((float) $grupo->sum('taxas'), 2),
                    'frete' => round((float) $grupo->sum('frete'), 2),
                    'lucro' => round((float) $grupo->sum('margem'), 2),
                ];
            })
            ->map(function (array $produto) {
                $produto['margem_pct'] = $produto['receita'] > 0 ? round($produto['lucro'] / $produto['receita'] * 100, 1) : null;

                return $produto;
            })
            ->values();
    }

    /** "Fonte Pet Branco" com cor "Branco" vira "Fonte Pet". */
    public static function semCor(string $nome, ?string $cor): string
    {
        $nome = trim($nome);
        if ($cor === null || $cor === '') {
            return $nome;
        }

        $semCor = preg_replace('/[\s\-|,\/]+'.preg_quote($cor, '/').'$/iu', '', $nome);

        return $semCor !== null && $semCor !== '' ? trim($semCor) : $nome;
    }
}

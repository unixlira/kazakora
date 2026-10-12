<?php

namespace App\Modules\Admin\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Curva ABC dos produtos de UM canal (menu Pesquisas, pedido 2026-10-12).
 *
 * - Só venda efetiva (mesmo filtro de Order::vendaEfetiva(), via
 *   ContributionMargin::semDevolucao) e pedidos pagos/enviados/concluídos.
 * - Variações (cores) somam no produto pai.
 * - Classe pelo faturamento acumulado, mesma regra do dashboard: A até 80%,
 *   B até 95%, o resto C. O produto que cruza o limite fica na faixa de cima.
 * - Por produto: faturamento dos itens, custo do cadastro e a taxa real do
 *   pedido rateada pelo valor do item. Frete pago pela loja e ADS só existem
 *   por canal, então o resultado oficial do canal vem de
 *   ContributionMargin::porCanal() e é mostrado à parte.
 */
class CurvaAbcPorCanal
{
    public const CANAIS = [
        Order::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
        Order::ORIGIN_SHOPEE => 'Shopee',
        Order::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        Order::ORIGIN_AMAZON => 'Amazon',
        Order::ORIGIN_STORE => 'Site próprio',
    ];

    public function __construct(private readonly ContributionMargin $margem) {}

    /**
     * @return array{canal: string, total: float, produtos: list<array>, resumo: array{a: int, b: int, c: int}, canal_oficial: array}
     */
    public function calcular(string $canal, Carbon $desde, Carbon $ate): array
    {
        $taxas = DB::table('order_channel_fees')
            ->selectRaw('order_id, SUM(fee_amount) as total')
            ->groupBy('order_id');

        $nome = 'COALESCE(pais.name, products.name, order_items.product_name)';
        $chave = 'COALESCE(pais.id, products.id)';

        $linhas = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('products as pais', 'pais.id', '=', 'products.parent_product_id')
            ->leftJoinSub($taxas, 'taxas', 'taxas.order_id', '=', 'orders.id')
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->when(
                $canal === Order::ORIGIN_STORE,
                fn ($q) => $q->where(fn ($w) => $w->where('orders.origin', $canal)->orWhereNull('orders.origin')),
                fn ($q) => $q->where('orders.origin', $canal),
            )
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q))
            ->selectRaw("{$chave} as id, {$nome} as nome,
                SUM(order_items.quantity) as quantidade,
                SUM(order_items.subtotal) as faturamento,
                SUM(".ContributionMargin::custoSql().') as custo,
                SUM(COALESCE(taxas.total, 0) * order_items.subtotal / NULLIF(orders.subtotal, 0)) as taxas,
                COUNT(DISTINCT orders.id) as pedidos')
            ->groupBy(DB::raw($chave), DB::raw($nome))
            ->get();

        $produtos = $linhas
            ->map(function ($linha) {
                $faturamento = round((float) $linha->faturamento, 2);
                $custo = round((float) $linha->custo, 2);
                $taxas = round((float) $linha->taxas, 2);

                return [
                    'id' => $linha->id !== null ? (int) $linha->id : null,
                    'nome' => (string) $linha->nome,
                    'pedidos' => (int) $linha->pedidos,
                    'quantidade' => (int) $linha->quantidade,
                    'faturamento' => $faturamento,
                    'custo' => $custo,
                    'taxas' => $taxas,
                    'margem' => round($faturamento - $custo - $taxas, 2),
                ];
            })
            ->filter(fn ($p) => $p['faturamento'] > 0)
            ->sortByDesc('faturamento')
            ->values();

        $total = round((float) $produtos->sum('faturamento'), 2);
        $acumulado = 0.0;
        $resumo = ['a' => 0, 'b' => 0, 'c' => 0];

        $produtos = $produtos->map(function ($produto) use ($total, &$acumulado, &$resumo) {
            $antes = $total > 0 ? $acumulado / $total * 100 : 100;
            $acumulado += $produto['faturamento'];
            $classe = $antes < 80 ? 'a' : ($antes < 95 ? 'b' : 'c');
            $resumo[$classe]++;

            return [
                ...$produto,
                'classe' => strtoupper($classe),
                'participacao' => $total > 0 ? round($produto['faturamento'] / $total * 100, 1) : 0.0,
                'acumulado' => $total > 0 ? round($acumulado / $total * 100, 1) : 0.0,
                'margem_percentual' => $produto['faturamento'] > 0 ? round($produto['margem'] / $produto['faturamento'] * 100, 1) : null,
            ];
        })->all();

        $oficial = $this->margem->porCanal($desde, $ate)[$canal] ?? [
            'pedidos' => 0, 'receita' => 0.0, 'custo_produtos' => 0.0, 'taxas' => 0.0,
            'frete' => 0.0, 'ads' => 0.0, 'bonificacoes' => 0.0, 'margem' => 0.0,
        ];

        return [
            'canal' => $canal,
            'total' => $total,
            'produtos' => $produtos,
            'resumo' => $resumo,
            'canal_oficial' => $oficial,
        ];
    }
}

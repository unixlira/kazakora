<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Marketplace\Support\ContributionMargin;
use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    // Use stable persisted values instead of model constants here because this
    // controller is often hot-deployed without every model symbol changing at
    // the same time on Hostinger.
    private const RETURN_ORIGINS = ['nota_devolucao_compra', 'nota_devolucao_venda'];

    /** "Vendas confirmadas" pros cards de faturamento — pending/awaiting_payment nunca foram pagos de verdade. */
    private const PAID_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    public function index(): Response
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();
        $margemMes = app(ContributionMargin::class)->periodo($startOfMonth, $today->copy()->addDay())['margem'];

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'ordersCount' => Order::query()->nonPurchaseReturn()->count(),
                // Achado real 2026-08-06: "FATURAMENTO" era all-time (sem
                // filtro de data nenhum) — ao lado de "FATURADO HOJE",
                // parecia representar um período, mas somava a loja inteira
                // desde o início. Escopado pro mês corrente, mesma definição
                // que o KoraSync (DashboardAgentController::metrics()) já
                // usava certo pra "revenue_month".
                //
                // BUG REAL 2026-08-15 (achado investigando reclamação real
                // do usuário — pedido #305 Shopee: R$44,99 no Seller Center,
                // R$58,24 registrado aqui): 'total' = subtotal + frete
                // (shipping_cost), correto pro VALOR DA NOTA FISCAL (exigido
                // pela SEFAZ, ver ShopeeDriver::importOrder()), mas o frete
                // pago pelo comprador/Shopee ao transportador nunca é receita
                // do vendedor — sem custo de frete equivalente subtraído em
                // lugar nenhum, ele inflava "faturamento" (e lucro) igual em
                // todo pedido com frete. CashFlowController já fazia certo
                // (gross_amount = subtotal); troquei 'total' por 'subtotal'
                // aqui e em todo outro lugar que soma "receita/faturamento"
                // pra bater com a definição já correta do Fluxo de Caixa.
                'revenue' => (float) Order::query()->nonPurchaseReturn()
                    ->where('created_at', '>=', $startOfMonth)
                    ->whereIn('status', self::PAID_STATUSES)
                    ->sum(DB::raw(ContributionMargin::receitaSql())),
                'ordersToday' => Order::query()->nonPurchaseReturn()->whereDate('created_at', $today)->count(),
                'ordersMonth' => Order::query()->nonPurchaseReturn()->where('created_at', '>=', $startOfMonth)->count(),
                'revenueToday' => (float) Order::query()->nonPurchaseReturn()
                    ->whereDate('created_at', $today)
                    ->whereIn('status', self::PAID_STATUSES)
                    ->sum(DB::raw(ContributionMargin::receitaSql())),
                // Faturamento de todos os canais desde o primeiro pedido e
                // margem do mês corrente — cards de valor do topo (pedido do
                // usuário 2026-10-06), mesma conta dos cards do mês/hoje.
                'revenueTotal' => (float) Order::query()->nonPurchaseReturn()
                    ->whereIn('status', self::PAID_STATUSES)
                    ->sum(DB::raw(ContributionMargin::receitaSql())),
                'contributionMarginMonth' => $margemMes,
                'returnsMonth' => StockMovement::query()
                    ->where('type', StockMovement::TYPE_RETURN)
                    ->where('created_at', '>=', $startOfMonth)
                    ->distinct()
                    ->count('product_id'),
            ],
            // Pedidos recentes e Estoque baixo saíram (pedido do usuário
            // 2026-10-06) — no lugar, mais vendidos e curva ABC.
            'topProducts' => $this->vendasPorProduto(Carbon::today()->subDays(29))
                ->sortByDesc('quantity')
                ->take(10)
                ->values()
                ->all(),
            'abcCurve' => $this->curvaAbc(Carbon::today()->subDays(89)),
            'revenueByChannel' => $this->revenueByChannel($startOfMonth),
            'analytics' => $this->analytics($today, $margemMes),
        ]);
    }

    /**
     * BUG REAL 2026-08-17 ("as métricas não estão funcionando", achado
     * verificando o painel inteiro): esta consulta nunca teve filtro de
     * data — somava TODO pedido pago desde o início da loja, enquanto
     * 'stats.revenue' ao lado (mesma tela, mesmo rótulo "Faturamento") já
     * era escopado pro mês corrente desde a correção de 2026-08-06 (ver
     * comentário em index()). Resultado visível: a soma "por canal" dava
     * maior que o total do mês (ex: R$12.871 de soma por canal contra
     * R$9.673 de "faturamento" no mesmo carregamento da tela) — parecia
     * quebrado porque, sem essa correção, literalmente não batia.
     *
     * @return array<int, array{origin: string, total: float}>
     */
    /**
     * Unidades e faturamento de cada produto vendido desde $desde (pedidos
     * pagos/enviados/concluídos, sem notas de devolução). Variação conta
     * como produto próprio — é ela que tem estoque. Item sem produto
     * cadastrado agrupa pelo nome.
     *
     * @return \Illuminate\Support\Collection<int, array{id: ?int, name: string, color: ?string, quantity: int, revenue: float}>
     */
    private function vendasPorProduto(Carbon $desde): \Illuminate\Support\Collection
    {
        $pedidos = Order::query()->nonPurchaseReturn()
            ->whereIn('status', self::PAID_STATUSES)
            ->where('created_at', '>=', $desde)
            ->select('id');

        return DB::table('order_items')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', $pedidos)
            ->selectRaw('order_items.product_id as id, COALESCE(products.name, order_items.product_name) as name, products.color as color,
                SUM(order_items.quantity) as quantity, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_id', DB::raw('COALESCE(products.name, order_items.product_name)'), 'products.color')
            ->get()
            ->map(fn ($linha) => [
                'id' => $linha->id !== null ? (int) $linha->id : null,
                'name' => (string) $linha->name,
                // Variações têm o mesmo nome e a cor no fim, que a tela corta.
                'color' => $linha->color ? trim((string) $linha->color) : null,
                'quantity' => (int) $linha->quantity,
                'revenue' => round((float) $linha->revenue, 2),
            ]);
    }

    /**
     * Curva ABC pelo faturamento: do produto que mais fatura pro que menos,
     * somando — A até 80% do faturamento, B até 95%, o resto é C (não vai
     * pra tela). O produto que cruza o limite fica na faixa de cima.
     *
     * @return array{total: float, a: list<array>, b: list<array>}
     */
    private function curvaAbc(Carbon $desde): array
    {
        $produtos = $this->vendasPorProduto($desde)->where('revenue', '>', 0)->sortByDesc('revenue')->values();
        $total = round((float) $produtos->sum('revenue'), 2);
        $curva = ['total' => $total, 'a' => [], 'b' => []];
        $acumulado = 0.0;

        foreach ($produtos as $produto) {
            $antes = $total > 0 ? $acumulado / $total * 100 : 100;
            $acumulado += $produto['revenue'];
            $produto['share'] = $total > 0 ? round($produto['revenue'] / $total * 100, 1) : 0.0;

            if ($antes < 80) {
                $curva['a'][] = $produto;
            } elseif ($antes < 95) {
                $curva['b'][] = $produto;
            } else {
                break;
            }
        }

        return $curva;
    }

    private function revenueByChannel(Carbon $startOfMonth): array
    {
        // subtotal, não total — ver comentário em index() sobre frete não
        // ser receita do vendedor.
        return Order::query()->nonPurchaseReturn()
            ->where('created_at', '>=', $startOfMonth)
            ->selectRaw('origin, SUM('.ContributionMargin::receitaSql().') as total')
            ->whereIn('status', self::PAID_STATUSES)
            ->groupBy('origin')
            ->get()
            ->map(fn ($row) => ['origin' => $row->origin, 'total' => (float) $row->total])
            ->all();
    }

    /**
     * Métricas e séries dos gráficos da dashboard (pedido do usuário
     * 2026-10-06: "métricas de financeiro que as grandes empresas usam",
     * faturamento, produto e crescimento em todos os canais). Mesma
     * definição de receita dos cards (ContributionMargin::receitaSql(),
     * pedidos pagos/enviados/concluídos, sem notas de devolução).
     *
     * Crescimento compara o mês até hoje com os MESMOS dias do mês anterior
     * (dia 1 ao dia N) — comparar o mês parcial com o mês anterior inteiro
     * faria todo começo de mês parecer queda.
     *
     * @return array<string, mixed>
     */
    private function analytics(Carbon $today, float $margem): array
    {
        $inicioMes = $today->copy()->startOfMonth();
        $inicioMesAnterior = $inicioMes->copy()->subMonthNoOverflow();
        $diaHoje = $today->day;
        $diasNoMes = $today->daysInMonth;
        $mesmoPeriodoAnterior = $inicioMesAnterior->copy()->addDays(min($diaHoje, $inicioMesAnterior->daysInMonth))->startOfDay();
        $inicio12Meses = $inicioMes->copy()->subMonthsNoOverflow(11);

        // Uma leitura só dos pedidos dos últimos 12 meses; o resto agrega
        // em PHP (evita função de data específica de banco).
        $pedidos = Order::query()->nonPurchaseReturn()
            ->whereIn('status', self::PAID_STATUSES)
            ->where('created_at', '>=', $inicio12Meses)
            ->selectRaw('id, origin, created_at, '.ContributionMargin::receitaSql().' as receita')
            ->get()
            ->map(fn ($pedido) => [
                'id' => $pedido->id,
                'canal' => $pedido->origin ?: Order::ORIGIN_STORE,
                'data' => Carbon::parse($pedido->created_at),
                'receita' => (float) $pedido->receita,
            ]);

        $doMes = $pedidos->filter(fn ($p) => $p['data']->gte($inicioMes));
        $mesmoPeriodo = $pedidos->filter(fn ($p) => $p['data']->gte($inicioMesAnterior) && $p['data']->lt($mesmoPeriodoAnterior));
        $mesAnterior = $pedidos->filter(fn ($p) => $p['data']->gte($inicioMesAnterior) && $p['data']->lt($inicioMes));

        $receitaMes = round($doMes->sum('receita'), 2);
        $receitaPeriodoAnterior = round($mesmoPeriodo->sum('receita'), 2);
        $itens = fn ($lista) => $lista->isEmpty() ? 0 : (int) DB::table('order_items')->whereIn('order_id', $lista->pluck('id'))->sum('quantity');
        $itensMes = $itens($doMes);
        $itensAnterior = $itens($mesmoPeriodo);

        $ticket = fn ($lista) => $lista->count() > 0 ? round($lista->sum('receita') / $lista->count(), 2) : 0.0;
        $variacao = fn (float $agora, float $antes) => $antes > 0 ? round(($agora - $antes) / $antes * 100, 1) : null;

        // Ritmo do mês: faturamento acumulado dia a dia, mês atual x anterior.
        $acumulado = function ($lista, Carbon $inicio, int $dias) {
            $porDia = $lista->groupBy(fn ($p) => $p['data']->day)->map(fn ($dia) => $dia->sum('receita'));
            $soma = 0.0;

            return collect(range(1, $dias))->map(function ($dia) use (&$soma, $porDia) {
                $soma += (float) ($porDia[$dia] ?? 0);

                return round($soma, 2);
            })->all();
        };

        $canais = $pedidos->pluck('canal')->unique()->values();
        $meses = collect(range(11, 0))->map(fn ($volta) => $inicioMes->copy()->subMonthsNoOverflow($volta));
        $porMes = $pedidos->groupBy(fn ($p) => $p['data']->format('Y-m'));

        return [
            'kpis' => [
                'ticketMedio' => $ticket($doMes),
                'ticketMedioAnterior' => $ticket($mesmoPeriodo),
                'crescimento' => $variacao($receitaMes, $receitaPeriodoAnterior),
                'receitaPeriodoAnterior' => $receitaPeriodoAnterior,
                'projecaoMes' => $diaHoje > 0 ? round($receitaMes / $diaHoje * $diasNoMes, 2) : 0.0,
                'receitaMesAnterior' => round($mesAnterior->sum('receita'), 2),
                'margemPercentual' => $receitaMes > 0 ? round($margem / $receitaMes * 100, 1) : null,
                'itensPorPedido' => $doMes->count() > 0 ? round($itensMes / $doMes->count(), 2) : 0.0,
                'itensPorPedidoAnterior' => $mesmoPeriodo->count() > 0 ? round($itensAnterior / $mesmoPeriodo->count(), 2) : 0.0,
                'pedidosMes' => $doMes->count(),
                'pedidosPeriodoAnterior' => $mesmoPeriodo->count(),
            ],
            'ritmo' => [
                'dia' => $diaHoje,
                'atual' => array_slice($acumulado($doMes, $inicioMes, $diasNoMes), 0, $diaHoje),
                'anterior' => $acumulado($mesAnterior, $inicioMesAnterior, $inicioMesAnterior->daysInMonth),
            ],
            'mensal' => $meses->map(fn (Carbon $mes) => [
                'mes' => $mes->format('Y-m'),
                'canais' => $canais->mapWithKeys(fn ($canal) => [
                    $canal => round(($porMes[$mes->format('Y-m')] ?? collect())->where('canal', $canal)->sum('receita'), 2),
                ])->all(),
            ])->filter(fn ($linha) => array_sum($linha['canais']) > 0)->values()->all(),
            'categorias' => $this->faturamentoPorCategoria($today->copy()->subDays(89)),
        ];
    }

    /**
     * Faturamento por categoria do produto (90 dias): as 6 maiores e o
     * resto em "Outras" — mais que isso vira arco-íris ilegível.
     *
     * @return list<array{nome: string, receita: float}>
     */
    private function faturamentoPorCategoria(Carbon $desde): array
    {
        $pedidos = Order::query()->nonPurchaseReturn()
            ->whereIn('status', self::PAID_STATUSES)
            ->where('created_at', '>=', $desde)
            ->select('id');

        $linhas = DB::table('order_items')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('order_items.order_id', $pedidos)
            ->selectRaw("COALESCE(categories.name, 'Sem categoria') as nome, SUM(order_items.subtotal) as receita")
            ->groupBy(DB::raw("COALESCE(categories.name, 'Sem categoria')"))
            ->orderByDesc('receita')
            ->get()
            ->map(fn ($linha) => ['nome' => (string) $linha->nome, 'receita' => round((float) $linha->receita, 2)]);

        if ($linhas->count() <= 7) {
            return $linhas->values()->all();
        }

        return $linhas->take(6)
            ->push(['nome' => 'Outras', 'receita' => round($linhas->slice(6)->sum('receita'), 2)])
            ->values()
            ->all();
    }
}

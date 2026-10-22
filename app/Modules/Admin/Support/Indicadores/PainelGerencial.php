<?php

namespace App\Modules\Admin\Support\Indicadores;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Checkout\Models\Order;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\CurvaAbc;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O que era a tela Gerenciador (/admin) até 2026-10-19, quando o Lira
 * aprovou o Fluxo de Caixa no lugar dela. Tudo foi para Indicadores, em
 * abas (Hoje e mês, Cauê e Diego, Produtos, Visitas KazaKora, Visitas FerraKora); a conta é a
 * mesma de antes, só mudou de casa (era DashboardController). O lucro
 * continua vindo de ContributionMargin.
 */
class PainelGerencial
{
    // Valores gravados, e não constantes do model (comentário original do
    // DashboardController).
    private const PAID_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    /**
     * Aba "Hoje e mês": os 8 cartões do topo, ritmo do mês, categorias,
     * mês a mês por canal e a faixa de faturamento por canal. Sem
     * financeiro.view o lucro não sai do servidor.
     *
     * @return array<string, mixed>
     */
    public function hojeEMes(bool $comLucro): array
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();
        $conta = $comLucro ? app(ContributionMargin::class)->periodo($startOfMonth, $today->copy()->addDay()) : null;

        $stats = [
            // Pedidos = vendas confirmadas (auditoria 2026-10-11).
            'ordersCount' => Order::query()->vendaEfetiva()->whereIn('status', self::PAID_STATUSES)->count(),
            // Faturamento do mês pela receita oficial (subtotal, sem frete;
            // ver ContributionMargin::receitaSql()).
            'revenue' => (float) Order::query()->vendaEfetiva()
                ->where('created_at', '>=', $startOfMonth)
                ->whereIn('status', self::PAID_STATUSES)
                ->sum(DB::raw(ContributionMargin::receitaSql())),
            'ordersToday' => Order::query()->vendaEfetiva()->whereIn('status', self::PAID_STATUSES)->whereDate('created_at', $today)->count(),
            'ordersMonth' => Order::query()->vendaEfetiva()->whereIn('status', self::PAID_STATUSES)->where('created_at', '>=', $startOfMonth)->count(),
            'revenueToday' => (float) Order::query()->vendaEfetiva()
                ->whereDate('created_at', $today)
                ->whereIn('status', self::PAID_STATUSES)
                ->sum(DB::raw(ContributionMargin::receitaSql())),
            'revenueTotal' => (float) Order::query()->vendaEfetiva()
                ->whereIn('status', self::PAID_STATUSES)
                ->sum(DB::raw(ContributionMargin::receitaSql())),
            'returnsMonth' => StockMovement::query()
                ->where('type', StockMovement::TYPE_RETURN)
                ->where('created_at', '>=', $startOfMonth)
                ->distinct()
                ->count('product_id'),
        ];
        if ($conta !== null) {
            // Lucro líquido (margem − imposto do Simples), o mesmo número do
            // Fluxo de Caixa e do e-mail das 23h50.
            $stats += [
                'contributionMarginMonth' => (float) $conta['margem'],
                'netProfitMonth' => (float) $conta['lucro'],
                'taxMonth' => (float) $conta['imposto'],
            ];
        }

        $analytics = $this->analytics($today, $conta !== null ? (float) $conta['lucro'] : null);

        return [
            'stats' => $stats,
            'analytics' => $analytics,
            'revenueByChannel' => $this->revenueByChannel($startOfMonth),
        ];
    }

    /**
     * Aba "Produtos": mais vendidos (30 dias) e curva A/B (90 dias).
     *
     * @return array{topProducts: list<array>, abcCurve: array}
     */
    public function produtos(): array
    {
        return [
            'topProducts' => $this->vendasPorProduto(Carbon::today()->subDays(29))
                ->sortByDesc('quantity')
                ->take(10)
                ->values()
                ->all(),
            'abcCurve' => $this->curvaAbc(Carbon::today()->subDays(89)),
        ];
    }

    /**
     * Unidades e faturamento de cada produto vendido desde $desde (pedidos
     * pagos/enviados/concluídos, sem notas de devolução). Variação conta
     * como produto próprio — é ela que tem estoque. Item sem produto
     * cadastrado agrupa pelo nome.
     *
     * @return \Illuminate\Support\Collection<int, array{id: ?int, name: string, color: ?string, quantity: int, revenue: float}>
     */
    public function vendasPorProduto(Carbon $desde): \Illuminate\Support\Collection
    {
        $pedidos = Order::query()->vendaEfetiva()
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
     * Curva ABC do painel (A e B; C não vai para a tela). A conta e a regra
     * das faixas são as mesmas do menu Oportunidades: CurvaAbc, com as
     * variações (cores) do mesmo produto juntas numa linha (2026-10-12).
     *
     * @return array{total: float, a: list<array>, b: list<array>}
     */
    public function curvaAbc(Carbon $desde): array
    {
        $curva = app(CurvaAbc::class);
        $produtos = $curva->classificar($curva->agrupar(app(ContributionMargin::class)
            ->porItem($desde, Carbon::tomorrow())['linhas']));

        $linha = fn (array $p) => [
            'id' => $p['id'],
            'name' => $p['nome'],
            'color' => count($p['variacoes']) > 1 ? count($p['variacoes']).' cores' : ($p['variacoes'][0] ?? null),
            'quantity' => $p['unidades'],
            'revenue' => $p['receita'],
            'share' => $p['participacao'],
        ];

        return [
            'total' => round((float) $produtos->sum('receita'), 2),
            'a' => $produtos->where('classe', 'A')->map($linha)->values()->all(),
            'b' => $produtos->where('classe', 'B')->map($linha)->values()->all(),
        ];
    }

    public function revenueByChannel(Carbon $startOfMonth): array
    {
        // subtotal, não total — ver comentário em index() sobre frete não
        // ser receita do vendedor.
        return Order::query()->vendaEfetiva()
            ->where('created_at', '>=', $startOfMonth)
            // Canal de métrica (2026-10-14): FerraKora e Beauty Kora saem do "site".
            ->selectRaw(Order::canalSql().' as canal, SUM('.ContributionMargin::receitaSql().') as total')
            ->whereIn('status', self::PAID_STATUSES)
            ->groupBy('canal')
            ->get()
            ->map(fn ($row) => ['origin' => $row->canal, 'total' => (float) $row->total])
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
    public function analytics(Carbon $today, ?float $lucro): array
    {
        $inicioMes = $today->copy()->startOfMonth();
        $inicioMesAnterior = $inicioMes->copy()->subMonthNoOverflow();
        $diaHoje = $today->day;
        $diasNoMes = $today->daysInMonth;
        $mesmoPeriodoAnterior = $inicioMesAnterior->copy()->addDays(min($diaHoje, $inicioMesAnterior->daysInMonth))->startOfDay();
        $inicio12Meses = $inicioMes->copy()->subMonthsNoOverflow(11);

        // Uma leitura só dos pedidos dos últimos 12 meses; o resto agrega
        // em PHP (evita função de data específica de banco).
        $pedidos = Order::query()->vendaEfetiva()
            ->whereIn('status', self::PAID_STATUSES)
            ->where('created_at', '>=', $inicio12Meses)
            ->selectRaw('id, origin, created_at, '.Order::canalSql().' as canal, '.ContributionMargin::receitaSql().' as receita')
            ->get()
            ->map(fn ($pedido) => [
                'id' => $pedido->id,
                'canal' => $pedido->canal ?: Order::ORIGIN_STORE,
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
                'margemPercentual' => $lucro !== null && $receitaMes > 0 ? round($lucro / $receitaMes * 100, 1) : null,
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
        $pedidos = Order::query()->vendaEfetiva()
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

    /**
     * Visitas de um site no mês escolhido (pedido 2026-10-11: gráfico,
     * visitantes do mês e do dia, filtro de mês). Conta só gente de fora:
     * sai quem entrou como equipe (admin/gerente) no mês, com tudo o que o
     * mesmo navegador visitou, e o visitante que passou de 300 páginas num
     * dia. Visitante = cookie kazakora_visitor; sem OK de cookies, um por
     * IP no dia (SiteVisit::visitantesPorDia).
     *
     * $site (pedido 2026-10-22): o banco é compartilhado e cada visita tem a
     * coluna site; a aba Visitas KazaKora lê 'kazakora' e a aba Visitas
     * FerraKora lê 'ferrakora' (sem cookie lá, um visitante por IP no dia).
     *
     * @return array<string, mixed>
     */
    public function visitantes(string $mes, string $site = SiteVisit::SITE_LOJA): array
    {
        $hoje = Carbon::today();
        $inicio = preg_match('/^\d{4}-\d{2}$/', $mes)
            ? Carbon::createFromFormat('!Y-m', $mes)->startOfMonth()
            : $hoje->copy()->startOfMonth();
        if ($inicio->greaterThan($hoje)) {
            $inicio = $hoje->copy()->startOfMonth();
        }
        $fim = $inicio->copy()->addMonthNoOverflow();

        $equipe = User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $porVisitante = SiteVisit::visitantesPorDia(
            SiteVisit::query()->where('site_visits.site', $site)->where('created_at', '>=', $inicio)->where('created_at', '<', $fim),
            $equipe,
        );
        // Regra de equipe e robô num lugar só: o Log de visitas usa a mesma.
        $linhas = SiteVisit::soDeFora($porVisitante);

        $porDia = $linhas->groupBy(fn ($l) => substr((string) $l->dia, 0, 10));
        $ultimoDia = $inicio->isSameMonth($hoje) ? $hoje->day : $inicio->daysInMonth;
        $dias = [];
        for ($d = 1; $d <= $ultimoDia; $d++) {
            $data = $inicio->copy()->day($d)->toDateString();
            $grupo = $porDia->get($data, collect());
            $dias[] = ['dia' => $data, 'visitantes' => $grupo->count(), 'paginas' => (int) $grupo->sum('paginas')];
        }

        $hojeStr = $hoje->toDateString();
        $deHoje = $porDia->get($hojeStr, collect());
        $primeira = SiteVisit::query()->where('site_visits.site', $site)->min('created_at');
        $meses = [];
        $cursor = $hoje->copy()->startOfMonth();
        $limite = $primeira ? Carbon::parse($primeira)->startOfMonth() : $cursor->copy();
        while ($cursor->greaterThanOrEqualTo($limite) && count($meses) < 24) {
            $meses[] = $cursor->format('Y-m');
            $cursor->subMonthNoOverflow();
        }

        $visitantesMes = $linhas->pluck('visitante')->unique()->count();
        $diasComDado = max(1, count($dias));

        return [
            'site' => $site,
            'mes' => $inicio->format('Y-m'),
            'meses' => $meses,
            'mesAtual' => $inicio->isSameMonth($hoje),
            'dias' => $dias,
            'visitantesMes' => $visitantesMes,
            'paginasMes' => (int) $linhas->sum('paginas'),
            'visitantesHoje' => $inicio->isSameMonth($hoje) ? $deHoje->count() : null,
            'paginasHoje' => $inicio->isSameMonth($hoje) ? (int) $deHoje->sum('paginas') : null,
            'mediaDia' => round(array_sum(array_column($dias, 'visitantes')) / $diasComDado, 1),
            'melhorDia' => collect($dias)->sortByDesc('visitantes')->first(),
        ];
    }

    /**
     * Últimos diagnósticos do Cauê (Shopee) e do Diego (Mercado Livre),
     * gerados pelo marketplace:diagnostico (ver DiagnosticarCanais).
     *
     * @return array<string, ?array>
     */
    public function diagnosticos(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('diagnosticos_canal')) {
            return ['shopee' => null, 'mercado_livre' => null];
        }
        $linhas = DB::table('diagnosticos_canal')->pluck('dados', 'canal');

        return [
            'shopee' => isset($linhas['shopee']) ? json_decode($linhas['shopee'], true) : null,
            'mercado_livre' => isset($linhas['mercado_livre']) ? json_decode($linhas['mercado_livre'], true) : null,
        ];
    }
}

<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const STATUS_LABELS = [
        Order::STATUS_PENDING => 'Pendente',
        Order::STATUS_PAID => 'Pago',
        Order::STATUS_SHIPPED => 'Enviado',
        Order::STATUS_COMPLETED => 'Concluído',
        Order::STATUS_CANCELLED => 'Cancelado',
    ];

    /** "Vendas confirmadas" pros cards de faturamento — pending/awaiting_payment nunca foram pagos de verdade. */
    private const PAID_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    public function index(): Response
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'ordersCount' => Order::query()->count(),
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
                'revenue' => (float) Order::query()
                    ->where('created_at', '>=', $startOfMonth)
                    ->whereIn('status', self::PAID_STATUSES)
                    ->sum('subtotal'),
                'productsCount' => Product::query()->where('is_active', true)->count(),
                'lowStockCount' => Product::query()->where('stock', '<=', 5)->count(),
                // "Visita" = IP diferente, não pageview — um mesmo visitante
                // navegando por várias páginas ou dando refresh no navegador
                // não deve inflar esse número (pedido explícito do usuário
                // 2026-08-07). Antes contava toda linha de site_visits (uma
                // por página carregada), o que inflava bastante.
                'visitsToday' => SiteVisit::query()->whereDate('created_at', $today)->distinct()->count('ip'),
                'ordersToday' => Order::query()->whereDate('created_at', $today)->count(),
                'ordersMonth' => Order::query()->where('created_at', '>=', $startOfMonth)->count(),
                'revenueToday' => (float) Order::query()
                    ->whereDate('created_at', $today)
                    ->whereIn('status', self::PAID_STATUSES)
                    ->sum('subtotal'),
                'returnsMonth' => StockMovement::query()
                    ->where('type', StockMovement::TYPE_RETURN)
                    ->where('created_at', '>=', $startOfMonth)
                    ->distinct()
                    ->count('product_id'),
                // Um snapshot só existe enquanto o carrinho tiver item (ver
                // CartManager::syncSnapshot) — "ativo" aqui só filtra sessões
                // que ainda não expiraram (mesma janela do SESSION_LIFETIME).
                'activeCartsCount' => CartSnapshot::query()
                    ->where('updated_at', '>=', now()->subMinutes((int) config('session.lifetime')))
                    ->count(),
                // site_visits.path já registra todo acesso à página de
                // produto — não precisa de tracking novo, só excluir a
                // sub-página de frete (/produtos/{slug}/envio).
                'productViewsCount' => SiteVisit::query()
                    ->where('path', 'like', '/produtos/%')
                    ->where('path', 'not like', '%/envio')
                    ->count(),
            ],
            'recentOrders' => Order::query()
                ->with('user:id,name')
                ->latest()
                ->limit(5)
                ->get(['id', 'user_id', 'status', 'total', 'created_at']),
            'lowStockProducts' => Product::query()
                ->where('stock', '<=', 5)
                ->orderBy('stock')
                ->limit(5)
                ->get(['id', 'name', 'stock']),
            'orderStatusBreakdown' => $this->orderStatusBreakdown(),
            'visitsSeries' => $this->visitsSeries(),
            'revenueSeries' => $this->revenueSeries(),
            'revenueByChannel' => $this->revenueByChannel(),
        ]);
    }

    /** @return array<int, array{origin: string, total: float}> */
    private function revenueByChannel(): array
    {
        // subtotal, não total — ver comentário em index() sobre frete não
        // ser receita do vendedor.
        return Order::query()
            ->selectRaw('origin, SUM(subtotal) as total')
            ->whereIn('status', self::PAID_STATUSES)
            ->groupBy('origin')
            ->get()
            ->map(fn ($row) => ['origin' => $row->origin, 'total' => (float) $row->total])
            ->all();
    }

    private function orderStatusBreakdown(): array
    {
        $counts = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(self::STATUS_LABELS)
            ->map(fn (string $label, string $status) => [
                'label' => $label,
                'total' => (int) ($counts[$status] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function visitsSeries(): array
    {
        $start = Carbon::today()->subMonths(3);

        $views = SiteVisit::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('date')
            ->pluck('total', 'date')
            ->all();

        // "Visitantes" = IP diferente por dia, não visitor_id (cookie) — ver
        // comentário em 'visitsToday' acima pra motivo. 'views' acima
        // continua sendo o pageview cru de propósito (métrica diferente,
        // útil pra saber engajamento, não é o que o usuário pediu pra mudar).
        $visitors = SiteVisit::query()
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT ip) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('date')
            ->pluck('total', 'date')
            ->all();

        return $this->fillDailySeries($start, fn (string $date) => [
            'views' => (int) ($views[$date] ?? 0),
            'visitors' => (int) ($visitors[$date] ?? 0),
        ]);
    }

    private function revenueSeries(): array
    {
        $start = Carbon::today()->subMonths(3);

        // subtotal, não total — ver comentário em index() sobre frete não
        // ser receita do vendedor.
        $rows = Order::query()
            ->selectRaw('DATE(created_at) as date, SUM(subtotal) as total')
            ->whereNot('status', Order::STATUS_CANCELLED)
            ->where('created_at', '>=', $start)
            ->groupBy('date')
            ->pluck('total', 'date')
            ->all();

        return $this->fillDailySeries($start, fn (string $date) => [
            'revenue' => (float) ($rows[$date] ?? 0),
        ]);
    }

    private function fillDailySeries(Carbon $start, callable $valueResolver): array
    {
        $series = [];

        for ($date = $start->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $key = $date->toDateString();
            $series[] = ['date' => $key, ...$valueResolver($key)];
        }

        return $series;
    }
}

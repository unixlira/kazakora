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
                'revenue' => (float) Order::query()->whereIn('status', self::PAID_STATUSES)->sum('total'),
                'productsCount' => Product::query()->where('is_active', true)->count(),
                'lowStockCount' => Product::query()->where('stock', '<=', 5)->count(),
                'visitsToday' => SiteVisit::query()->whereDate('created_at', $today)->count(),
                'ordersToday' => Order::query()->whereDate('created_at', $today)->count(),
                'ordersMonth' => Order::query()->where('created_at', '>=', $startOfMonth)->count(),
                'revenueToday' => (float) Order::query()
                    ->whereDate('created_at', $today)
                    ->whereIn('status', self::PAID_STATUSES)
                    ->sum('total'),
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
        ]);
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
        $start = Carbon::today()->subDays(13);

        $views = SiteVisit::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('date')
            ->pluck('total', 'date')
            ->all();

        $visitors = SiteVisit::query()
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT visitor_id) as total')
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
        $start = Carbon::today()->subDays(13);

        $rows = Order::query()
            ->selectRaw('DATE(created_at) as date, SUM(total) as total')
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

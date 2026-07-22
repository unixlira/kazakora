<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class KpiController extends Controller
{
    public function index(): Response
    {
        $startOfMonth = Carbon::today()->startOfMonth();

        $ordersMonth = Order::query()->where('created_at', '>=', $startOfMonth);
        $totalOrdersMonth = (clone $ordersMonth)->count();
        $cancelledOrdersMonth = (clone $ordersMonth)->where('status', Order::STATUS_CANCELLED)->count();
        $validOrders = Order::query()->whereNot('status', Order::STATUS_CANCELLED);
        $validOrdersMonth = (clone $ordersMonth)->whereNot('status', Order::STATUS_CANCELLED);

        $averageTicket = (clone $validOrders)->count() > 0
            ? (float) (clone $validOrders)->sum('total') / (clone $validOrders)->count()
            : 0.0;

        $uniqueVisitorsMonth = SiteVisit::query()
            ->where('created_at', '>=', $startOfMonth)
            ->distinct()
            ->count('visitor_id');

        $conversionRate = $uniqueVisitorsMonth > 0
            ? ($validOrdersMonth->count() / $uniqueVisitorsMonth) * 100
            : 0.0;

        $productsCount = Product::query()->count();
        $lowStockCount = Product::query()->where('stock', '<=', 5)->count();

        return Inertia::render('Admin/Indicadores/Index', [
            'kpis' => [
                'averageTicket' => $averageTicket,
                'conversionRate' => round($conversionRate, 2),
                'cancellationRate' => $totalOrdersMonth > 0 ? round(($cancelledOrdersMonth / $totalOrdersMonth) * 100, 2) : 0.0,
                'lowStockRate' => $productsCount > 0 ? round(($lowStockCount / $productsCount) * 100, 2) : 0.0,
                'uniqueVisitorsMonth' => $uniqueVisitorsMonth,
                'ordersMonth' => $totalOrdersMonth,
            ],
        ]);
    }
}

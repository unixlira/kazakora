<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::today()->subDays(29);
        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : Carbon::today()->endOfDay();

        $orders = Order::query()
            ->whereNot('status', Order::STATUS_CANCELLED)
            ->whereBetween('created_at', [$from, $to]);

        $salesByDay = (clone $orders)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as orders_count, SUM(total) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNot('orders.status', Order::STATUS_CANCELLED)
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('order_items.product_name, SUM(order_items.quantity) as quantity_sold, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity_sold')
            ->limit(10)
            ->get();

        return Inertia::render('Admin/Relatorios/Index', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'salesByDay' => $salesByDay,
            'topProducts' => $topProducts,
            'summary' => [
                'ordersCount' => (clone $orders)->count(),
                'revenue' => (float) (clone $orders)->sum('total'),
            ],
        ]);
    }
}

<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'ordersCount' => Order::query()->count(),
                'revenue' => (float) Order::query()->whereNot('status', Order::STATUS_CANCELLED)->sum('total'),
                'productsCount' => Product::query()->count(),
                'lowStockCount' => Product::query()->where('stock', '<=', 5)->count(),
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
        ]);
    }
}

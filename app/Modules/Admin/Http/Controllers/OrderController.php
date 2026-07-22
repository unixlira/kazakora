<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    private const STATUSES = [
        Order::STATUS_PENDING,
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
        Order::STATUS_CANCELLED,
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/Orders/Index', [
            'orders' => Order::query()->with('user:id,name,email')->withCount('items')->latest()->get(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function show(Order $order): Response
    {
        return Inertia::render('Admin/Orders/Show', [
            'order' => $order->load(['items', 'user:id,name,email']),
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $order->update($validated);

        return back()->with('success', 'Status do pedido atualizado.');
    }
}

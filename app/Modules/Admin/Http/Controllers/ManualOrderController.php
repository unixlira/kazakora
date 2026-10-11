<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ManualOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ManualOrderController extends Controller
{
    private const CHANNELS = [
        Order::ORIGIN_STORE,
        Order::ORIGIN_MERCADO_LIVRE,
        Order::ORIGIN_SHOPEE,
        Order::ORIGIN_TIKTOK_SHOP,
        Order::ORIGIN_AMAZON,
        Order::ORIGIN_SHEIN,
    ];

    private const STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
    ];

    public function create(): Response
    {
        return Inertia::render('Admin/Orders/CreateManual', [
            'channels' => self::CHANNELS,
            'statuses' => self::STATUSES,
            'products' => Product::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'price', 'stock']),
        ]);
    }

    public function store(Request $request, ManualOrderService $service): RedirectResponse
    {
        $validated = $request->validate([
            'origin' => ['required', Rule::in(self::CHANNELS)],
            'external_order_id' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'buyer_document' => ['required', 'string', 'max:20'],
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_phone' => ['required', 'string', 'max:30'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'buyer_whatsapp' => ['nullable', 'string', 'max:30'],
            'shipping_zip' => ['required', 'string', 'max:9'],
            'shipping_street' => ['required', 'string', 'max:255'],
            'shipping_number' => ['required', 'string', 'max:20'],
            'shipping_complement' => ['nullable', 'string', 'max:255'],
            'shipping_neighborhood' => ['required', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:255'],
            'shipping_state' => ['required', 'string', 'size:2'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $order = $service->create($validated);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['external_order_id' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('admin.pedidos.exibir', $order)->with('success', 'Pedido criado manualmente com sucesso.');
    }
}

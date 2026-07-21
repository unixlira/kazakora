<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Support\CartManager;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Order;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartManager $cart,
        private readonly StockManager $stock,
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Checkout/Index', [
            'items' => $this->cart->items(),
            'total' => $this->cart->total(),
            'user' => $request->user()->only('name', 'email'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:20'],
            'shipping_zip' => ['required', 'string', 'max:9'],
            'shipping_street' => ['required', 'string', 'max:255'],
            'shipping_number' => ['required', 'string', 'max:20'],
            'shipping_complement' => ['nullable', 'string', 'max:255'],
            'shipping_neighborhood' => ['required', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:255'],
            'shipping_state' => ['required', 'string', 'size:2'],
        ]);

        $cartItems = $this->cart->items();

        if ($cartItems->isEmpty()) {
            return back()->withErrors(['cart' => 'Seu carrinho está vazio.']);
        }

        try {
            $order = DB::transaction(function () use ($data, $request, $cartItems) {
                $products = Product::query()
                    ->whereIn('id', $cartItems->pluck('product.id'))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $order = Order::create([
                    ...$data,
                    'user_id' => $request->user()->id,
                    'status' => Order::STATUS_PENDING,
                ]);

                $total = 0;

                foreach ($cartItems as $item) {
                    $product = $products->get($item['product']->id);
                    $quantity = min($item['quantity'], $product->stock);

                    if ($quantity < 1) {
                        continue;
                    }

                    $subtotal = round($product->price * $quantity, 2);
                    $total += $subtotal;

                    $order->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_price' => $product->price,
                        'quantity' => $quantity,
                        'subtotal' => $subtotal,
                    ]);

                    $this->stock->adjust(
                        $product,
                        -$quantity,
                        StockMovement::TYPE_SALE,
                        reason: "Pedido #{$order->id}",
                        reference: $order,
                    );
                }

                if ($total === 0) {
                    throw new RuntimeException('empty_order');
                }

                $order->update(['subtotal' => $total, 'total' => $total]);

                return $order;
            });
        } catch (RuntimeException) {
            return back()->withErrors(['cart' => 'Os produtos do seu carrinho não estão mais disponíveis.']);
        }

        $this->cart->clear();

        Mail::to($request->user())->send(new OrderConfirmation($order));

        return redirect()
            ->route('checkout.confirmation', $order)
            ->with('success', 'Pedido realizado com sucesso!');
    }

    public function confirmation(Request $request, Order $order): Response
    {
        abort_if($order->user_id !== $request->user()->id, 403);

        return Inertia::render('Checkout/Confirmation', [
            'order' => $order->load('items'),
        ]);
    }
}

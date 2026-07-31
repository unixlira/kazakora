<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Cart\Support\CartManager;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\GuestEmailAlreadyExistsException;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    private const SESSION_KEY = 'checkout_draft';

    public function __construct(
        private readonly CartManager $cart,
        private readonly StockManager $stock,
        private readonly StripePaymentService $stripe,
        private readonly OrderPaymentFinalizer $finalizer,
    ) {
    }

    public function delivery(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Checkout/Delivery', [
            'items' => $this->cart->items(),
            'total' => $this->cart->total(),
            'productsDiscount' => $this->productsDiscount(),
            'addresses' => $user ? $user->addresses : [],
            'shippingMethods' => ShippingMethod::query()->where('is_active', true)->orderBy('price')->get(['id', 'name', 'estimated_days', 'price']),
            'guest' => $user ? null : ['name' => '', 'email' => '', 'cpf' => ''],
            'draft' => session(self::SESSION_KEY),
        ]);
    }

    public function storeDelivery(Request $request): RedirectResponse
    {
        if ($this->cart->items()->isEmpty()) {
            return back()->withErrors(['cart' => 'Seu carrinho está vazio.']);
        }

        $rules = [
            'shipping_method_id' => ['required', 'exists:shipping_methods,id'],
            'address_id' => ['nullable', 'integer'],
            'new_address' => ['required_without:address_id', 'array'],
            'new_address.label' => ['nullable', 'string', 'max:60'],
            'new_address.recipient_name' => ['required_without:address_id', 'string', 'max:255'],
            'new_address.phone' => ['required_without:address_id', 'string', 'max:20'],
            'new_address.zip' => ['required_without:address_id', 'string', 'max:9'],
            'new_address.street' => ['required_without:address_id', 'string', 'max:255'],
            'new_address.number' => ['required_without:address_id', 'string', 'max:20'],
            'new_address.complement' => ['nullable', 'string', 'max:255'],
            'new_address.neighborhood' => ['required_without:address_id', 'string', 'max:255'],
            'new_address.city' => ['required_without:address_id', 'string', 'max:255'],
            'new_address.state' => ['required_without:address_id', 'string', 'size:2'],
        ];

        if (! $request->user()) {
            $rules['guest'] = ['required', 'array'];
            $rules['guest.name'] = ['required', 'string', 'max:255'];
            $rules['guest.email'] = ['required', 'email', 'max:255'];
            $rules['guest.cpf'] = ['required', 'string', 'max:14'];
        }

        $data = $request->validate($rules);

        if ($request->user() && ! empty($data['address_id'])) {
            $request->user()->addresses()->findOrFail($data['address_id']);
        }

        if (! $request->user() && User::where('email', $data['guest']['email'])->exists()) {
            return back()->withErrors(['guest.email' => 'Já existe uma conta com esse e-mail. Faça login para continuar.']);
        }

        $request->session()->put(self::SESSION_KEY, $data);

        return redirect()->route('finalizacao.pagamento');
    }

    public function payment(Request $request): RedirectResponse|Response
    {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! $draft || $this->cart->items()->isEmpty()) {
            return redirect()->route('finalizacao.entrega');
        }

        return Inertia::render('Checkout/Payment', $this->paymentProps($draft));
    }

    public function applyCoupon(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:60']]);

        $coupon = Coupon::query()->where('code', $data['code'])->where('is_active', true)->first();

        if (! $coupon) {
            return back()->withErrors(['code' => 'Cupom inválido.']);
        }

        $draft = $request->session()->get(self::SESSION_KEY, []);
        $draft['coupon_code'] = $coupon->code;
        $request->session()->put(self::SESSION_KEY, $draft);

        return back()->with('success', 'Cupom aplicado!');
    }

    /**
     * Cria o pedido (status awaiting_payment) e o(s) PaymentIntent(s). Cartão
     * primeiro (captura manual, cancelável); Pix/boleto sempre por último —
     * eles não têm fase de "autorizado sem capturar" no Stripe, então só são
     * criados depois que a parte com cartão já autorizou com segurança.
     */
    public function storePayment(Request $request): RedirectResponse|Response
    {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! $draft) {
            return redirect()->route('finalizacao.entrega');
        }

        $data = $request->validate([
            'payment_method' => ['required', 'in:card,pix,boleto'],
            'split' => ['boolean'],
            'payment_method_secondary' => ['required_if:split,true', 'nullable', 'in:card,pix,boleto', 'different:payment_method'],
            'split_percentage' => ['required_if:split,true', 'nullable', 'integer', 'min:1', 'max:99'],
            'terms_accepted' => ['accepted'],
        ]);

        $cartItems = $this->cart->items();

        if ($cartItems->isEmpty()) {
            return back()->withErrors(['cart' => 'Seu carrinho está vazio.']);
        }

        if (! $this->stripe->isConfigured()) {
            return back()->withErrors(['payment' => 'Pagamento ainda não está disponível — aguarde a configuração do Stripe.']);
        }

        $shippingMethod = ShippingMethod::findOrFail($draft['shipping_method_id']);
        $subtotal = round($cartItems->sum('subtotal'), 2);
        $coupon = ! empty($draft['coupon_code'])
            ? Coupon::query()->where('code', $draft['coupon_code'])->where('is_active', true)->first()
            : null;
        $discount = $coupon ? $coupon->discountFor($subtotal) : 0;
        $total = round($subtotal - $discount + (float) $shippingMethod->price, 2);

        $methods = $data['split'] ?? false
            ? $this->sortMethodsSafely([$data['payment_method'], $data['payment_method_secondary']])
            : [$data['payment_method']];

        try {
            [$order, $firstIntent] = DB::transaction(function () use ($request, $draft, $cartItems, $shippingMethod, $subtotal, $coupon, $discount, $total, $methods, $data) {
                $user = $request->user() ?? $this->createGuestAccount($draft['guest']);

                $address = ! empty($draft['address_id'])
                    ? $user->addresses()->findOrFail($draft['address_id'])
                    : $user->addresses()->create([...$draft['new_address'], 'is_default' => $user->addresses()->count() === 0]);

                $order = Order::create([
                    'user_id' => $user->id,
                    'status' => Order::STATUS_AWAITING_PAYMENT,
                    'shipping_method_id' => $shippingMethod->id,
                    'shipping_name' => $address->recipient_name,
                    'shipping_phone' => $address->phone,
                    'shipping_zip' => $address->zip,
                    'shipping_street' => $address->street,
                    'shipping_number' => $address->number,
                    'shipping_complement' => $address->complement,
                    'shipping_neighborhood' => $address->neighborhood,
                    'shipping_city' => $address->city,
                    'shipping_state' => $address->state,
                    'subtotal' => $subtotal,
                    'shipping_cost' => $shippingMethod->price,
                    'coupon_code' => $coupon?->code,
                    'discount_amount' => $discount,
                    'total' => $total,
                ]);

                $this->createOrderItems($order, $cartItems);

                $primaryMethod = $methods[0];
                $primaryAmount = count($methods) > 1
                    ? round($total * ($data['split_percentage'] / 100), 2)
                    : $total;

                $intent = $this->stripe->createIntent(
                    $primaryMethod,
                    $primaryAmount,
                    ['order_id' => $order->id],
                    "order:{$order->id}:payment:1",
                );

                Payment::create([
                    'order_id' => $order->id,
                    'stripe_payment_intent_id' => $intent->id,
                    'method_type' => $primaryMethod,
                    'amount' => $primaryAmount,
                    'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
                ]);

                return [$order, $intent];
            });
        } catch (GuestEmailAlreadyExistsException) {
            return back()->withErrors(['guest.email' => 'Já existe uma conta com esse e-mail. Faça login para continuar.']);
        }

        return Inertia::render('Checkout/Payment', [
            ...$this->paymentProps($draft),
            'order' => $order->only('id', 'total'),
            'clientSecret' => $firstIntent->client_secret,
            'stripeKey' => config('services.stripe.key'),
            'pendingSecondMethod' => count($methods) > 1 ? $methods[1] : null,
        ]);
    }

    /**
     * Chamado pelo front-end depois que a primeira parte (cartão) confirmou
     * com segurança — só então criamos a parte irreversível (Pix/boleto).
     */
    public function storeSecondPayment(Request $request, Order $order): Response
    {
        abort_unless($order->user_id === optional($request->user())->id, 403);

        $data = $request->validate([
            'method_type' => ['required', 'in:card,pix,boleto'],
        ]);

        $alreadyPaid = $order->payments()->sum('amount');
        $remaining = round((float) $order->total - (float) $alreadyPaid, 2);

        $intent = $this->stripe->createIntent(
            $data['method_type'],
            $remaining,
            ['order_id' => $order->id],
            "order:{$order->id}:payment:2",
        );

        Payment::create([
            'order_id' => $order->id,
            'stripe_payment_intent_id' => $intent->id,
            'method_type' => $data['method_type'],
            'amount' => $remaining,
            'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
        ]);

        return Inertia::render('Checkout/Payment', [
            ...$this->paymentProps($request->session()->get(self::SESSION_KEY, [])),
            'order' => $order->only('id', 'total'),
            'clientSecret' => $intent->client_secret,
            'stripeKey' => config('services.stripe.key'),
            'pendingSecondMethod' => null,
        ]);
    }

    /**
     * Chamado pelo navegador do cliente depois que o(s) Payment Element(s)
     * confirmaram do lado do Stripe.js. Reverifica direto com o Stripe (não
     * confia só no que o front-end diz), finaliza o pedido e limpa o
     * carrinho — só esta rota tem acesso à sessão de verdade do cliente
     * (o webhook não tem, por isso ele não limpa o carrinho).
     */
    public function finalize(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === optional($request->user())->id, 403);

        foreach ($order->payments as $payment) {
            if ($payment->status === Payment::STATUS_REQUIRES_CONFIRMATION) {
                $intent = $this->stripe->retrieve($payment->stripe_payment_intent_id);

                if (in_array($intent->status, ['requires_capture', 'succeeded'], true)) {
                    $payment->update(['status' => Payment::STATUS_AUTHORIZED]);
                } elseif ($intent->status === 'canceled') {
                    $payment->update(['status' => Payment::STATUS_CANCELED]);
                }
            }
        }

        $paid = $this->finalizer->finalize($order->fresh());

        if (! $paid) {
            return back()->withErrors(['payment' => 'Pagamento ainda não confirmado. Tente novamente em instantes.']);
        }

        $this->cart->clear();
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('finalizacao.confirmacao', $order)->with('success', 'Pedido realizado com sucesso!');
    }

    public function confirmation(Request $request, Order $order): Response
    {
        abort_if($order->user_id !== $request->user()->id, 403);

        return Inertia::render('Checkout/Confirmation', [
            'order' => $order->load('items'),
        ]);
    }

    private function paymentProps(array $draft): array
    {
        $cartItems = $this->cart->items();
        $subtotal = round($cartItems->sum('subtotal'), 2);
        $shippingMethod = ! empty($draft['shipping_method_id']) ? ShippingMethod::find($draft['shipping_method_id']) : null;
        $shippingCost = (float) ($shippingMethod->price ?? 0);
        $coupon = ! empty($draft['coupon_code'])
            ? Coupon::query()->where('code', $draft['coupon_code'])->where('is_active', true)->first()
            : null;
        $discount = $coupon ? $coupon->discountFor($subtotal) : 0;

        return [
            'items' => $cartItems,
            'subtotal' => $subtotal,
            'productsDiscount' => $this->productsDiscount(),
            'shippingCost' => $shippingCost,
            'couponCode' => $coupon?->code,
            'discountAmount' => $discount,
            'total' => round($subtotal - $discount + $shippingCost, 2),
            'originalTotal' => round($subtotal + $shippingCost, 2),
        ];
    }

    private function productsDiscount(): float
    {
        $cartItems = $this->cart->items();
        $original = $cartItems->sum(fn ($item) => (float) $item['product']->price * $item['quantity']);
        $actual = $cartItems->sum('subtotal');

        return round($original - $actual, 2);
    }

    private function createOrderItems(Order $order, $cartItems): void
    {
        $products = Product::query()
            ->whereIn('id', $cartItems->pluck('product.id'))
            ->with('quantityDiscounts')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($cartItems as $item) {
            $product = $products->get($item['product']->id);
            $quantity = min($item['quantity'], $product->stock);

            if ($quantity < 1) {
                continue;
            }

            $unitPrice = $product->unitPriceForQuantity($quantity);

            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => round($unitPrice * $quantity, 2),
            ]);

            $this->stock->adjust(
                $product,
                -$quantity,
                StockMovement::TYPE_SALE,
                reason: "Pedido #{$order->id}",
                reference: $order,
            );
        }
    }

    private function createGuestAccount(array $guest): User
    {
        if (User::where('email', $guest['email'])->exists()) {
            throw new GuestEmailAlreadyExistsException();
        }

        $user = User::create([
            'name' => $guest['name'],
            'email' => $guest['email'],
            'cpf' => $guest['cpf'],
            'password' => Hash::make(Str::random(40)),
            'role' => User::ROLE_CUSTOMER,
        ]);

        Auth::login($user);
        Password::sendResetLink(['email' => $user->email]);

        return $user;
    }

    private function sortMethodsSafely(array $methods): array
    {
        $rank = fn (string $method) => $method === Payment::METHOD_CARD ? 0 : 1;
        usort($methods, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $methods;
    }
}

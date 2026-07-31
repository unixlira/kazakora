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
use App\Modules\Checkout\Services\FreightQuoteService;
use App\Modules\Checkout\Support\GuestEmailAlreadyExistsException;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Http\JsonResponse;
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
        private readonly FreightQuoteService $freight,
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

    /**
     * Cotação de frete ao vivo (Melhor Envio) pro CEP informado — chamada
     * via JS assim que o CEP de entrega é preenchido. Nunca falha "alto":
     * FreightQuoteService já devolve lista vazia se não for possível cotar,
     * e o front cai de volta pras formas de envio estáticas nesse caso.
     */
    public function quoteFreight(Request $request): JsonResponse
    {
        $data = $request->validate(['zip' => ['required', 'string']]);

        $cartItems = $this->cart->items();

        if ($cartItems->isEmpty()) {
            return response()->json(['quotes' => []]);
        }

        return response()->json(['quotes' => $this->freight->quote($cartItems, $data['zip'])]);
    }

    public function storeDelivery(Request $request): RedirectResponse
    {
        if ($this->cart->items()->isEmpty()) {
            return back()->withErrors(['cart' => 'Seu carrinho está vazio.']);
        }

        $rules = [
            'shipping_method_id' => [
                'required',
                function ($attribute, $value, $fail) use ($request): void {
                    // Cotação ao vivo do Melhor Envio não tem linha na
                    // tabela shipping_methods — só as formas de envio
                    // estáticas cadastradas em /admin/logistica precisam
                    // existir de verdade no banco.
                    if (! $request->filled('shipping_quote') && ! ShippingMethod::whereKey($value)->exists()) {
                        $fail('Forma de envio inválida.');
                    }
                },
            ],
            'shipping_quote' => ['nullable', 'array'],
            'shipping_quote.name' => ['required_with:shipping_quote', 'string', 'max:255'],
            'shipping_quote.carrier_name' => ['nullable', 'string', 'max:255'],
            'shipping_quote.price' => ['required_with:shipping_quote', 'numeric', 'min:0'],
            'shipping_quote.estimated_days' => ['nullable', 'integer', 'min:0'],
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

        if ($request->user() && $resumable = $this->resumePendingOrder($request->user())) {
            return $this->renderConfirmingPayment($draft, ...$resumable);
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

        // Trava contra duplo clique/retry: se já existe um pedido aguardando
        // pagamento com uma parcela ainda viva no Stripe, reaproveita em vez
        // de criar um pedido/cobrança novo pro mesmo carrinho.
        if ($request->user() && $resumable = $this->resumePendingOrder($request->user())) {
            return $this->renderConfirmingPayment($draft, ...$resumable);
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

        $shipping = $this->resolveShipping($draft);
        $subtotal = round($cartItems->sum('subtotal'), 2);
        $coupon = ! empty($draft['coupon_code'])
            ? Coupon::query()->where('code', $draft['coupon_code'])->where('is_active', true)->first()
            : null;
        $discount = $coupon ? $coupon->discountFor($subtotal) : 0;
        $total = round($subtotal - $discount + $shipping['cost'], 2);

        $methods = $data['split'] ?? false
            ? $this->sortMethodsSafely([$data['payment_method'], $data['payment_method_secondary']])
            : [$data['payment_method']];

        try {
            [$order, $firstIntent] = DB::transaction(function () use ($request, $draft, $cartItems, $shipping, $subtotal, $coupon, $discount, $total, $methods, $data) {
                $user = $request->user() ?? $this->createGuestAccount($draft['guest']);

                $address = ! empty($draft['address_id'])
                    ? $user->addresses()->findOrFail($draft['address_id'])
                    : $user->addresses()->create([...$draft['new_address'], 'is_default' => $user->addresses()->count() === 0]);

                $order = Order::create([
                    'user_id' => $user->id,
                    'status' => Order::STATUS_AWAITING_PAYMENT,
                    'shipping_method_id' => $shipping['method_id'],
                    'shipping_carrier_name' => $shipping['carrier_name'],
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
                    'shipping_cost' => $shipping['cost'],
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

        return $this->renderConfirmingPayment($draft, $order, $firstIntent, $methods[0], count($methods) > 1 ? $methods[1] : null);
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

        return $this->renderConfirmingPayment($request->session()->get(self::SESSION_KEY, []), $order, $intent, $data['method_type']);
    }

    /**
     * Chamado pelo front-end em polling (a cada poucos segundos) enquanto o
     * modal de "processando pagamento" estiver aberto. Reverifica direto com
     * o Stripe (nunca confia só no que o front-end diz), finaliza o pedido
     * via OrderPaymentFinalizer — a mesma fonte da verdade que o webhook usa
     * — e só esta rota limpa o carrinho (é a única com acesso à sessão real
     * do cliente; o webhook não tem sessão nenhuma).
     */
    public function status(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === optional($request->user())->id, 403);

        foreach ($order->payments as $payment) {
            if ($payment->status !== Payment::STATUS_REQUIRES_CONFIRMATION) {
                continue;
            }

            $intent = $this->stripe->retrieve($payment->stripe_payment_intent_id);

            if (in_array($intent->status, ['requires_capture', 'succeeded'], true)) {
                $payment->update(['status' => Payment::STATUS_AUTHORIZED]);
            } elseif ($intent->status === 'canceled') {
                $payment->update(['status' => Payment::STATUS_CANCELED]);
                $this->finalizer->cancelSiblingsAfterFailure($order->fresh(['payments']), $payment);
            }
        }

        $wasPaid = $order->status === Order::STATUS_PAID;

        if ($this->finalizer->finalize($order->fresh(['payments'])) && ! $wasPaid) {
            $this->cart->clear();
            $request->session()->forget(self::SESSION_KEY);
        }

        $order->refresh();

        return response()->json([
            'status' => $order->status,
            'redirect' => $order->status === Order::STATUS_PAID ? route('finalizacao.confirmacao', $order) : null,
        ]);
    }

    public function confirmation(Request $request, Order $order): Response
    {
        abort_if($order->user_id !== $request->user()->id, 403);

        return Inertia::render('Checkout/Confirmation', [
            'order' => $order->load('items'),
        ]);
    }

    public function myOrders(Request $request): Response
    {
        return Inertia::render('Checkout/MyOrders', [
            'orders' => $request->user()->orders()->with('items')->latest()->paginate(10),
        ]);
    }

    /**
     * Encontra um pedido do usuário ainda aguardando pagamento com uma
     * parcela genuinamente viva no Stripe (não só no nosso banco — reverifica
     * de verdade). Usado tanto pra evitar criar um pedido duplicado num
     * duplo clique/retry quanto pra retomar a tela de confirmação se o
     * cliente atualizar a aba no meio do processamento.
     *
     * @return array{0: Order, 1: \Stripe\PaymentIntent, 2: string}|null
     */
    private function resumePendingOrder(User $user): ?array
    {
        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('status', Order::STATUS_AWAITING_PAYMENT)
            ->with('payments')
            ->latest()
            ->first();

        if (! $order) {
            return null;
        }

        $pendingPayment = $order->payments->firstWhere('status', Payment::STATUS_REQUIRES_CONFIRMATION);

        if (! $pendingPayment) {
            return null;
        }

        try {
            $intent = $this->stripe->retrieve($pendingPayment->stripe_payment_intent_id);
        } catch (\Throwable) {
            return null;
        }

        if (! in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
            return null;
        }

        return [$order, $intent, $pendingPayment->method_type];
    }

    private function renderConfirmingPayment(array $draft, Order $order, \Stripe\PaymentIntent $intent, string $methodType, ?string $pendingSecondMethod = null): Response
    {
        return Inertia::render('Checkout/Payment', [
            ...$this->paymentProps($draft),
            'order' => $order->only('id', 'total'),
            'clientSecret' => $intent->client_secret,
            'stripeKey' => config('services.stripe.key'),
            'methodType' => $methodType,
            'pixExpiresAfterSeconds' => $methodType === Payment::METHOD_PIX ? StripePaymentService::PIX_EXPIRES_AFTER_SECONDS : null,
            'pendingSecondMethod' => $pendingSecondMethod,
        ]);
    }

    private function paymentProps(array $draft): array
    {
        $cartItems = $this->cart->items();
        $subtotal = round($cartItems->sum('subtotal'), 2);
        $shippingCost = ! empty($draft['shipping_method_id']) ? $this->resolveShipping($draft)['cost'] : 0.0;
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

    /**
     * @return array{method_id: ?int, carrier_name: ?string, cost: float}
     */
    private function resolveShipping(array $draft): array
    {
        if (! empty($draft['shipping_quote'])) {
            $quote = $draft['shipping_quote'];

            return [
                'method_id' => null,
                'carrier_name' => $quote['carrier_name'] ?? $quote['name'] ?? 'Frete',
                'cost' => (float) $quote['price'],
            ];
        }

        $shippingMethod = ShippingMethod::findOrFail($draft['shipping_method_id']);

        return [
            'method_id' => $shippingMethod->id,
            'carrier_name' => $shippingMethod->name,
            'cost' => (float) $shippingMethod->price,
        ];
    }

    private function sortMethodsSafely(array $methods): array
    {
        $rank = fn (string $method) => $method === Payment::METHOD_CARD ? 0 : 1;
        usort($methods, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $methods;
    }
}

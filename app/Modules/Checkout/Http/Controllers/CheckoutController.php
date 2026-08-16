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
use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Support\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;

class CheckoutController extends Controller
{
    private const SESSION_KEY = 'checkout_draft';

    public function __construct(
        private readonly CartManager $cart,
        private readonly StockManager $stock,
        private readonly StripePaymentService $stripe,
        private readonly MercadoPagoPaymentService $mercadoPago,
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
        // Todo erro nesta ação redireciona explicitamente pra tela de
        // entrega (nunca back()) — back() depende de _previous.url, que
        // reflete a última página GET vista nesta sessão e pode estar
        // apontando pra qualquer lugar (inclusive um endpoint JSON-only como
        // /finalizacao/frete) se o usuário navegou entre abas ou ficou
        // muito tempo na tela. Já causou a tela "trava"/mostra JSON cru.
        if ($this->cart->items()->isEmpty()) {
            return redirect()->route('finalizacao.entrega')->withErrors(['cart' => 'Seu carrinho está vazio.']);
        }

        // address_id só existe no payload de um usuário autenticado (o
        // formulário de convidado nunca manda esse campo) — se ele veio mas
        // $request->user() é null, a sessão expirou/foi perdida entre o
        // carregamento da página e o envio do formulário. Sem esse guard,
        // isso cai nas regras de "guest" abaixo (todas ausentes) e o usuário
        // só vê a mesma tela de novo, sem entender por quê.
        if (! $request->user() && $request->filled('address_id')) {
            Log::warning('checkout.storeDelivery.session_lost_mid_flow', [
                'session_id' => $request->session()->getId(),
            ]);

            return redirect()->route('finalizacao.entrega')->withErrors(['session' => 'Sua sessão expirou. Faça login novamente para continuar.']);
        }

        $rules = [
            'shipping_method_id' => [
                'required',
                function ($attribute, $value, $fail): void {
                    // IDs de cotação ao vivo (Melhor Envio "me:", Correios
                    // "correios:") são confirmados depois — se o front não
                    // mandou os detalhes junto (shipping_quote), o backend
                    // reconsulta a API sozinho logo abaixo, em vez de
                    // recusar aqui. Só um ID estático (numérico, de
                    // shipping_methods) precisa já existir de verdade nesse
                    // ponto.
                    if (! $this->isLiveQuoteId((string) $value) && ! ShippingMethod::whereKey($value)->exists()) {
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
            'new_address' => ['nullable', 'required_without:address_id', 'array'],
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

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->route('finalizacao.entrega')->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        if ($request->user() && ! empty($data['address_id'])) {
            $address = $request->user()->addresses()->findOrFail($data['address_id']);
        }

        if (! $request->user() && User::where('email', $data['guest']['email'])->exists()) {
            return redirect()->route('finalizacao.entrega')->withErrors(['guest.email' => 'Já existe uma conta com esse e-mail. Faça login para continuar.']);
        }

        // Reforço server-side: se o ID é de cotação ao vivo mas o front não
        // mandou (ou mandou incompleto) o shipping_quote — por qualquer
        // motivo do lado do navegador — recotamos aqui em vez de recusar.
        // Não depende de o front nunca dessincronizar os dois campos.
        if ($this->isLiveQuoteId((string) $data['shipping_method_id']) && empty($data['shipping_quote'])) {
            $zip = $data['new_address']['zip'] ?? ($address ?? null)?->zip;
            $quotes = $zip ? $this->freight->quote($this->cart->items(), $zip) : [];
            $match = collect($quotes)->firstWhere('id', $data['shipping_method_id']);

            if (! $match) {
                return redirect()->route('finalizacao.entrega')->withErrors(['shipping_method_id' => 'Não foi possível confirmar essa forma de envio agora. Selecione novamente e tente de novo.']);
            }

            $data['shipping_quote'] = [
                'name' => $match['name'],
                'carrier_name' => $match['carrier_name'],
                'price' => $match['price'],
                'estimated_days' => $match['estimated_days'],
            ];
        }

        $request->session()->put(self::SESSION_KEY, $data);
        // Grava a sessão já aqui (em vez de esperar a fase terminate() do
        // kernel) — sob PHP-FPM com fastcgi_finish_request, a resposta do
        // redirect pode chegar ao navegador antes do terminate() rodar, e o
        // Inertia segue o redirect rápido o bastante pra ler a sessão antes
        // da escrita ter sido persistida (bounce de volta pra "entrega").
        $request->session()->save();

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
            return redirect()->route('finalizacao.pagamento')->withErrors(['code' => 'Cupom inválido.']);
        }

        $draft = $request->session()->get(self::SESSION_KEY, []);
        $draft['coupon_code'] = $coupon->code;
        $request->session()->put(self::SESSION_KEY, $draft);
        $request->session()->save();

        return redirect()->route('finalizacao.pagamento')->with('success', 'Cupom aplicado!');
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

        $paymentValidator = Validator::make($request->all(), [
            'payment_method' => ['required', 'in:card,pix'],
            'split' => ['boolean'],
            // "different:payment_method" só faz sentido quando split está
            // ativo — sem isso, o valor padrão do front (payment_method_secondary
            // sempre mandado, mesmo sem split) rejeitava qualquer compra só de
            // Pix, já que o padrão de payment_method_secondary também é "pix".
            'payment_method_secondary' => [
                'nullable',
                'in:card,pix',
                Rule::requiredIf(fn () => $request->boolean('split')),
                Rule::when($request->boolean('split'), ['different:payment_method']),
            ],
            'split_percentage' => ['required_if:split,true', 'nullable', 'integer', 'min:1', 'max:99'],
            'terms_accepted' => ['accepted'],
        ]);

        if ($paymentValidator->fails()) {
            return redirect()->route('finalizacao.pagamento')->withErrors($paymentValidator)->withInput();
        }

        $data = $paymentValidator->validated();

        $cartItems = $this->cart->items();

        if ($cartItems->isEmpty()) {
            return redirect()->route('finalizacao.entrega')->withErrors(['cart' => 'Seu carrinho está vazio.']);
        }

        // Gateway ativo decide tudo (não é mais só o Pix) — cartão vai pro
        // Mercado Pago via token do Card Payment Brick, gerado no front-end
        // antes deste POST (o backend nunca vê o número do cartão, igual
        // ao Stripe Elements). Split sempre tem cartão como parte 1
        // (sortMethodsSafely), então qualquer split com MP ativo também
        // precisa do token.
        $useMercadoPago = PaymentGateway::active() === PaymentGateway::MERCADOPAGO;
        $includesCard = $data['payment_method'] === Payment::METHOD_CARD || ($data['split'] ?? false);

        if ($useMercadoPago && $includesCard) {
            $cardValidator = Validator::make($request->all(), [
                'mp_card_token' => ['required', 'string'],
                'mp_card_installments' => ['required', 'integer', 'min:1'],
                'mp_payment_method_id' => ['required', 'string'],
                'mp_issuer_id' => ['nullable', 'string'],
            ]);

            if ($cardValidator->fails()) {
                return redirect()->route('finalizacao.pagamento')->withErrors($cardValidator)->withInput();
            }

            $data = [...$data, ...$cardValidator->validated()];
        }

        if ($useMercadoPago && ! $this->mercadoPago->isConfigured()) {
            return redirect()->route('finalizacao.pagamento')->withErrors(['payment' => 'Pagamento ainda não está disponível — aguarde a configuração do Mercado Pago.']);
        }

        if (! $useMercadoPago && ! $this->stripe->isConfigured()) {
            return redirect()->route('finalizacao.pagamento')->withErrors(['payment' => 'Pagamento ainda não está disponível — aguarde a configuração do Stripe.']);
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
            [$order, $paymentResult] = DB::transaction(function () use ($request, $draft, $cartItems, $shipping, $subtotal, $coupon, $discount, $total, $methods, $data, $useMercadoPago) {
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
                $isSplit = count($methods) > 1;
                $primaryAmount = $isSplit
                    ? round($total * ($data['split_percentage'] / 100), 2)
                    : $total;

                // Pix (sempre a única parcela — split sempre tem cartão como
                // parte 1, ver sortMethodsSafely) vai pela API de Payments
                // clássica, não Orders — ver MercadoPagoPaymentService.
                if ($useMercadoPago && $primaryMethod === Payment::METHOD_PIX) {
                    $mpPayment = $this->mercadoPago->createPixPayment(
                        $primaryAmount,
                        $this->mercadoPagoPayer($user),
                        "order-{$order->id}-payment-1",
                        "order:{$order->id}:payment:1",
                    );

                    Payment::create([
                        'order_id' => $order->id,
                        'provider' => Payment::PROVIDER_MERCADOPAGO,
                        'mercadopago_payment_id' => (string) $mpPayment['id'],
                        'method_type' => $primaryMethod,
                        'amount' => $primaryAmount,
                        'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
                    ]);

                    return [$order, $mpPayment];
                }

                if ($useMercadoPago) {
                    $mpOrder = $this->mercadoPago->createOrder(
                        $this->mercadoPagoOrderPayload($primaryAmount, $user, $data, ! $isSplit, "order-{$order->id}-payment-1"),
                        "order:{$order->id}:payment:1",
                    );
                    $mpOrderPayment = $mpOrder['transactions']['payments'][0] ?? [];

                    if (in_array($mpOrderPayment['status'] ?? null, ['failed', 'rejected'], true)) {
                        throw new MercadoPagoException(
                            'Pagamento no cartão recusado: '.($mpOrderPayment['status_detail'] ?? 'motivo não informado pela operadora.'),
                        );
                    }

                    Payment::create([
                        'order_id' => $order->id,
                        'provider' => Payment::PROVIDER_MERCADOPAGO,
                        'mercadopago_order_id' => (string) $mpOrder['id'],
                        'mercadopago_payment_id' => isset($mpOrderPayment['id']) ? (string) $mpOrderPayment['id'] : null,
                        'method_type' => $primaryMethod,
                        'amount' => $primaryAmount,
                        'status' => $this->mercadoPagoLocalStatus($mpOrderPayment),
                    ]);

                    return [$order, $mpOrder];
                }

                $intent = $this->stripe->createIntent(
                    $primaryMethod,
                    $primaryAmount,
                    ['order_id' => $order->id],
                    "order:{$order->id}:payment:1",
                );

                Payment::create([
                    'order_id' => $order->id,
                    'provider' => Payment::PROVIDER_STRIPE,
                    'stripe_payment_intent_id' => $intent->id,
                    'method_type' => $primaryMethod,
                    'amount' => $primaryAmount,
                    'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
                ]);

                return [$order, $intent];
            });
        } catch (GuestEmailAlreadyExistsException) {
            return redirect()->route('finalizacao.pagamento')->withErrors(['guest.email' => 'Já existe uma conta com esse e-mail. Faça login para continuar.']);
        } catch (ApiErrorException $exception) {
            // Erro real da API do Stripe (ex: método de pagamento não
            // ativado no dashboard, credencial inválida, instabilidade) —
            // sem isso, essa exceção subia sem tratamento e virava uma 500
            // crua, sem nenhum feedback pro cliente no checkout.
            Log::channel('stripe')->error('stripe.checkout.payment_intent_failed', [
                'payment_method' => $data['payment_method'],
                'message' => $exception->getMessage(),
            ]);

            return redirect()->route('finalizacao.pagamento')->withErrors(['payment' => 'Não foi possível iniciar o pagamento agora. Tente novamente em instantes ou escolha outra forma de pagamento.']);
        } catch (MercadoPagoException $exception) {
            Log::channel('mercadopago')->error('mercadopago.checkout.payment_failed', [
                'payment_method' => $data['payment_method'],
                'message' => $exception->getMessage(),
                'context' => $exception->context(),
            ]);

            // Achado real 2026-08-06: $exception->getMessage() aqui é o
            // texto CRU devolvido pela API do Mercado Pago
            // (MercadoPagoPaymentService::handleResponse() usa
            // $response->json('message') direto), não uma mensagem pensada
            // pra cliente — coisas como nome de parâmetro interno ou motivo
            // técnico de rejeição iam parar na tela de checkout de qualquer
            // comprador. Log já guarda o texto real pra investigação; só a
            // mensagem genérica (mesma do catch do Stripe acima) vai pro
            // cliente agora.
            return redirect()->route('finalizacao.pagamento')->withErrors(['payment' => 'Não foi possível iniciar o pagamento agora. Tente novamente em instantes ou escolha outra forma de pagamento.']);
        }

        if ($useMercadoPago) {
            if ($methods[0] === Payment::METHOD_PIX) {
                return $this->renderConfirmingMercadoPagoPix($draft, $order, $paymentResult);
            }

            // Cartão via MP já resolve na hora (aprovado/autorizado), sem
            // etapa de confirmação no front-end como o Stripe exige — se
            // for a única parcela, já está indo pro polling normal (mesmo
            // mecanismo do Pix). Se for split, ainda falta criar o Pix da
            // segunda parcela — o front-end chama proxima-parte sozinho,
            // sem esperar nenhuma ação do cliente (não há nada pra
            // confirmar do lado dele nessa etapa).
            return $this->renderConfirmingMercadoPagoCard($draft, $order, count($methods) > 1 ? $methods[1] : null);
        }

        return $this->renderConfirmingPayment($draft, $order, $paymentResult, $methods[0], count($methods) > 1 ? $methods[1] : null);
    }

    /**
     * Chamado pelo front-end depois que a primeira parte (cartão) confirmou
     * com segurança — só então criamos a parte irreversível (Pix/boleto).
     * Com Mercado Pago ativo essa segunda parte é sempre Pix (cartão é
     * sempre a parte 1, ver sortMethodsSafely()) e não precisa de nenhuma
     * confirmação do cliente pra ser chamada — o front-end dispara isso
     * sozinho assim que a parte 1 resolve, sem esperar clique nenhum.
     */
    public function storeSecondPayment(Request $request, Order $order): Response
    {
        abort_unless($order->user_id === optional($request->user())->id, 403);

        $data = $request->validate([
            'method_type' => ['required', 'in:card,pix'],
        ]);

        $alreadyPaid = $order->payments()->sum('amount');
        $remaining = round((float) $order->total - (float) $alreadyPaid, 2);
        $draft = $request->session()->get(self::SESSION_KEY, []);

        if (PaymentGateway::active() === PaymentGateway::MERCADOPAGO) {
            // Sempre Pix (cartão é sempre a parte 1, ver sortMethodsSafely)
            // — API de Payments clássica, ver createPixPayment().
            try {
                $mpPayment = $this->mercadoPago->createPixPayment(
                    $remaining,
                    $this->mercadoPagoPayer($order->user),
                    "order-{$order->id}-payment-2",
                    "order:{$order->id}:payment:2",
                );
            } catch (MercadoPagoException $exception) {
                Log::channel('mercadopago')->error('mercadopago.checkout.second_payment_failed', [
                    'order_id' => $order->id,
                    'message' => $exception->getMessage(),
                    'context' => $exception->context(),
                ]);

                return $this->renderConfirmingMercadoPagoCard($draft, $order);
            }

            Payment::create([
                'order_id' => $order->id,
                'provider' => Payment::PROVIDER_MERCADOPAGO,
                'mercadopago_payment_id' => (string) $mpPayment['id'],
                'method_type' => $data['method_type'],
                'amount' => $remaining,
                'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
            ]);

            return $this->renderConfirmingMercadoPagoPix($draft, $order, $mpPayment);
        }

        $intent = $this->stripe->createIntent(
            $data['method_type'],
            $remaining,
            ['order_id' => $order->id],
            "order:{$order->id}:payment:2",
        );

        Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_STRIPE,
            'stripe_payment_intent_id' => $intent->id,
            'method_type' => $data['method_type'],
            'amount' => $remaining,
            'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
        ]);

        return $this->renderConfirmingPayment($draft, $order, $intent, $data['method_type']);
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

            if ($payment->provider === Payment::PROVIDER_MERCADOPAGO && $payment->mercadopago_order_id) {
                // Cartão — API de Orders.
                $mpOrder = $this->mercadoPago->retrieveOrder($payment->mercadopago_order_id);
                $mpPayment = $mpOrder['transactions']['payments'][0] ?? [];

                if (in_array($mpPayment['status'] ?? null, ['failed', 'rejected', 'cancelled'], true)) {
                    $payment->update(['status' => Payment::STATUS_CANCELED]);
                    $this->finalizer->cancelSiblingsAfterFailure($order->fresh(['payments']), $payment);
                } else {
                    $localStatus = $this->mercadoPagoLocalStatus($mpPayment);

                    if ($localStatus !== Payment::STATUS_REQUIRES_CONFIRMATION) {
                        $payment->update(['status' => $localStatus]);
                    }
                }

                continue;
            }

            if ($payment->provider === Payment::PROVIDER_MERCADOPAGO) {
                // Pix — API de Payments clássica, vocabulário de status
                // diferente (approved/pending/rejected/cancelled).
                $mpPayment = $this->mercadoPago->retrievePayment($payment->mercadopago_payment_id);

                if ($mpPayment['status'] === 'approved') {
                    $payment->update(['status' => Payment::STATUS_CAPTURED]);
                } elseif (in_array($mpPayment['status'], ['cancelled', 'rejected'], true)) {
                    $payment->update(['status' => Payment::STATUS_CANCELED]);
                    $this->finalizer->cancelSiblingsAfterFailure($order->fresh(['payments']), $payment);
                }

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

        if ($pendingPayment->provider === Payment::PROVIDER_MERCADOPAGO) {
            // Pix via Mercado Pago não usa Stripe Elements — não há nada
            // pra "retomar" no sentido desta função (client secret/Payment
            // Element); recarregar a página simplesmente reinicia a escolha
            // de método. O QR já emitido continua válido até expirar.
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

    /**
     * Pix via Mercado Pago já vem com o QR code pronto na criação — não
     * precisa de nenhuma etapa de confirmação no front-end (sem client
     * secret, sem SDK JS pra montar), só mostrar e começar o polling.
     */
    /** Pix é sempre a API de Payments clássica — ver MercadoPagoPaymentService::createPixPayment(). */
    private function renderConfirmingMercadoPagoPix(array $draft, Order $order, array $mpPayment): Response
    {
        $qr = $mpPayment['point_of_interaction']['transaction_data'] ?? [];

        return Inertia::render('Checkout/Payment', [
            ...$this->paymentProps($draft),
            'order' => $order->only('id', 'total'),
            'methodType' => Payment::METHOD_PIX,
            'mercadoPagoPix' => [
                'qrCode' => $qr['qr_code'] ?? null,
                'qrCodeBase64' => $qr['qr_code_base64'] ?? null,
                'expiresAt' => $mpPayment['date_of_expiration'] ?? null,
            ],
        ]);
    }

    /**
     * Cartão via MP já foi aprovado/autorizado de forma síncrona no
     * storePayment() — não existe "Payment Element" nem etapa de
     * confirmação aqui como no Stripe. Sem segunda parcela pendente, o
     * front-end só precisa começar o polling normal (mesmo endpoint que o
     * Pix usa) pra pegar a confirmação e liberar a tela de sucesso.
     */
    private function renderConfirmingMercadoPagoCard(array $draft, Order $order, ?string $pendingSecondMethod = null): Response
    {
        return Inertia::render('Checkout/Payment', [
            ...$this->paymentProps($draft),
            'order' => $order->only('id', 'total'),
            'methodType' => Payment::METHOD_CARD,
            'mercadoPagoCardConfirmed' => true,
            'pendingSecondMethod' => $pendingSecondMethod,
        ]);
    }

    /**
     * $user->cpf já está sempre preenchido nesse ponto (usuário autenticado
     * com CPF cadastrado, ou conta de convidado — createGuestAccount() seta
     * o CPF do formulário na criação) — não depende mais do draft da sessão,
     * o que permite reusar isto na segunda parcela de um split (onde o
     * draft pode já não fazer mais sentido consultar).
     *
     * @return array<string, mixed>
     */
    private function mercadoPagoPayer(User $user): array
    {
        $cpf = preg_replace('/\D/', '', $user->cpf ?? '');
        $nameParts = preg_split('/\s+/', trim($user->name), 2);

        return array_filter([
            'email' => $user->email,
            'first_name' => $nameParts[0] ?? $user->name,
            'last_name' => $nameParts[1] ?? $nameParts[0] ?? $user->name,
            'identification' => $cpf ? ['type' => 'CPF', 'number' => $cpf] : null,
        ]);
    }

    /**
     * Monta o corpo de uma Order (Checkout Transparente) com uma única
     * transação — cartão e Pix só diferem no formato de payment_method.
     * $capture false autoriza sem capturar (dá pra cancelar sem tirar
     * dinheiro se a outra parte de um pagamento dividido falhar) — a API de
     * Orders controla isso em capture_mode no nível da order, não por
     * transação.
     */
    /**
     * Só cartão — Pix usa a API de Payments clássica (createPixPayment), não
     * a de Orders. "bank_transfer" (Pix) chegou a ser suportado aqui, mas a
     * API de Orders rejeita esse type nessa conta especificamente, então
     * essa função nunca mais recebe $method = pix na prática.
     */
    private function mercadoPagoOrderPayload(float $amount, User $user, array $data, bool $capture, string $externalReference): array
    {
        return [
            'type' => 'online',
            'processing_mode' => 'automatic',
            'capture_mode' => $capture ? 'automatic' : 'manual',
            'external_reference' => $externalReference,
            'total_amount' => (string) $amount,
            'payer' => $this->mercadoPagoPayer($user),
            'transactions' => [
                'payments' => [[
                    'amount' => (string) $amount,
                    'payment_method' => [
                        'id' => $data['mp_payment_method_id'],
                        'type' => 'credit_card',
                        'token' => $data['mp_card_token'],
                        'installments' => (int) $data['mp_card_installments'],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Vocabulário real de status da API de Orders (confirmado testando
     * cartões de teste oficiais direto contra a API, não documentação —
     * "approved"/"authorized"/"rejected" da API de Payments antiga não
     * existem aqui):
     * - "processed" + status_detail "accredited" → pago/capturado de verdade.
     * - "action_required" + status_detail "waiting_capture" → autorizado,
     *   aguardando captura manual (parte 1 de um split).
     * - "action_required" + qualquer outro status_detail (ex:
     *   "waiting_transfer" do Pix) → ainda pendente de confirmação.
     * - "failed"/"rejected" → recusado (tratado antes de chegar aqui).
     */
    private function mercadoPagoLocalStatus(array $mpPayment): string
    {
        return match (true) {
            ($mpPayment['status'] ?? null) === 'processed' => Payment::STATUS_CAPTURED,
            ($mpPayment['status'] ?? null) === 'action_required' && ($mpPayment['status_detail'] ?? null) === 'waiting_capture' => Payment::STATUS_AUTHORIZED,
            default => Payment::STATUS_REQUIRES_CONFIRMATION,
        };
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
            'paymentGateway' => PaymentGateway::active(),
            'mercadoPagoPublicKey' => PaymentGateway::active() === PaymentGateway::MERCADOPAGO
                ? config('services.mercadopago.public_key')
                : null,
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

    /**
     * IDs de cotação ao vivo (não de uma linha estática de shipping_methods)
     * — "me:" da Melhor Envio, "correios:" da API dos Correios (ver
     * FreightQuoteService/CorreiosFreightQuoteService).
     */
    private function isLiveQuoteId(string $value): bool
    {
        return str_starts_with($value, 'me:') || str_starts_with($value, 'correios:');
    }

    private function sortMethodsSafely(array $methods): array
    {
        $rank = fn (string $method) => $method === Payment::METHOD_CARD ? 0 : 1;
        usort($methods, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $methods;
    }
}

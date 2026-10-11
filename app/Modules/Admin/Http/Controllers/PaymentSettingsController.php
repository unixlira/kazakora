<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Support\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentSettingsController extends Controller
{
    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly MercadoPagoPaymentService $mercadoPago,
    ) {
    }

    public function edit(): Response
    {
        return Inertia::render('Admin/Pagamentos/Index', [
            'activeProvider' => PaymentGateway::active(),
            'gateways' => [
                PaymentGateway::MERCADOPAGO => [
                    'label' => 'Mercado Pago',
                    'configured' => $this->mercadoPago->isConfigured(),
                    'description' => 'Cartão, Pix e boleto. Padrão da loja.',
                ],
                PaymentGateway::STRIPE => [
                    'label' => 'Stripe',
                    'configured' => $this->stripe->isConfigured(),
                    'description' => 'Cartão, Pix e boleto.',
                ],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(PaymentGateway::ALL)],
        ]);

        PaymentGateway::setActive($validated['provider']);

        return back()->with('success', 'Gateway de pagamento atualizado.');
    }
}

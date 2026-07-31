<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentSettingsController extends Controller
{
    private const SETTING_KEY = 'active_payment_provider';

    public const PROVIDER_STRIPE = 'stripe';

    public const PROVIDER_MERCADOPAGO = 'mercadopago';

    private const PROVIDERS = [self::PROVIDER_STRIPE, self::PROVIDER_MERCADOPAGO];

    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly MercadoPagoPaymentService $mercadoPago,
    ) {
    }

    public static function activeProvider(): string
    {
        return Setting::get(self::SETTING_KEY, self::PROVIDER_MERCADOPAGO);
    }

    public function edit(): Response
    {
        return Inertia::render('Admin/Pagamentos/Index', [
            'activeProvider' => self::activeProvider(),
            'gateways' => [
                self::PROVIDER_MERCADOPAGO => [
                    'label' => 'Mercado Pago',
                    'configured' => $this->mercadoPago->isConfigured(),
                    'description' => 'Cartão, Pix e boleto. Padrão da loja.',
                ],
                self::PROVIDER_STRIPE => [
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
            'provider' => ['required', Rule::in(self::PROVIDERS)],
        ]);

        Setting::set(self::SETTING_KEY, $validated['provider']);

        return back()->with('success', 'Gateway de pagamento atualizado.');
    }
}

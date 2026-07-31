<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Qual gateway processa o checkout (Stripe ou Mercado Pago) — escolhido pelo
 * admin em /admin/pagamentos, guardado via Setting. Mercado Pago é o padrão
 * (Stripe só libera Pix depois de 60 dias de conta ativa; a maioria dos
 * clientes paga via Pix).
 */
class PaymentGateway
{
    private const SETTING_KEY = 'active_payment_provider';

    public const STRIPE = 'stripe';

    public const MERCADOPAGO = 'mercadopago';

    public const ALL = [self::STRIPE, self::MERCADOPAGO];

    public static function active(): string
    {
        return Setting::get(self::SETTING_KEY, self::MERCADOPAGO);
    }

    public static function setActive(string $provider): void
    {
        Setting::set(self::SETTING_KEY, $provider);
    }
}

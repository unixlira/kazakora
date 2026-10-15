<?php

namespace App\Http\Controllers\Api;

use App\Modules\FerraKora\Services\CheckoutFerraKora;

/**
 * Checkout próprio do site FerraKora (pedido do Lira 2026-10-14), em
 * ferrakora.devlira.com.br/checkout. Rotas e regras em CheckoutOutroSiteController.
 */
class FerraKoraCheckoutController extends CheckoutOutroSiteController
{
    public function __construct(CheckoutFerraKora $checkout)
    {
        parent::__construct($checkout);
    }
}

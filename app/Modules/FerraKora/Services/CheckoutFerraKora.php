<?php

namespace App\Modules\FerraKora\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CheckoutOutroSite;
use App\Modules\FerraKora\Models\FerraKoraProduto;

/**
 * Checkout próprio do FerraKora (pedido do Lira 2026-10-14). As regras
 * (preço, entrega, Pix, cartão, conta do cliente) ficam em CheckoutOutroSite.
 */
class CheckoutFerraKora extends CheckoutOutroSite
{
    public function site(): string
    {
        return Order::SITE_FERRAKORA;
    }

    protected function modeloPublicado(): string
    {
        return FerraKoraProduto::class;
    }

    protected function nomeDoSite(): string
    {
        return 'FerraKora';
    }
}

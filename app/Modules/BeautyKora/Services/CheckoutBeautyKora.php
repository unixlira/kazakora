<?php

namespace App\Modules\BeautyKora\Services;

use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CheckoutOutroSite;

/**
 * Checkout do Beauty Kora pelo Mercado Pago da KazaKora (pedido do Lira
 * 2026-10-14, só homolog): sai o checkout da Hostinger, o dinheiro cai na
 * mesma conta da KazaKora. Regras em CheckoutOutroSite. Só produtos do
 * catálogo do KazaKora (kzv-); os da Hostinger não passam por aqui.
 */
class CheckoutBeautyKora extends CheckoutOutroSite
{
    public function site(): string
    {
        return Order::SITE_BEAUTYKORA;
    }

    protected function modeloPublicado(): string
    {
        return BeautyKoraProduto::class;
    }

    protected function nomeDoSite(): string
    {
        return 'Beauty Kora';
    }
}

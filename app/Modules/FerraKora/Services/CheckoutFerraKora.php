<?php

namespace App\Modules\FerraKora\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CheckoutOutroSite;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraProduto;

/**
 * Checkout próprio do FerraKora (pedido do Lira 2026-10-14). As regras
 * (preço, entrega, Pix, cartão) ficam em CheckoutOutroSite. Desde 2026-10-15
 * o comprador é cliente do FerraKora (FerraKoraCliente), não da KazaKora.
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

    protected function contaNaKazaKora(): bool
    {
        return false;
    }

    protected function clienteDoSite(array $cliente): array
    {
        return ['ferrakora_cliente_id' => FerraKoraCliente::doCheckout($cliente)->id];
    }
}

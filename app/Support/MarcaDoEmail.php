<?php

namespace App\Support;

use App\Modules\Checkout\Models\Order;

/**
 * Marca dos e-mails ao comprador (pedido do Lira 2026-10-15: FerraKora
 * separado da KazaKora). Pedido feito no site FerraKora sai com o nome, o
 * logo (topo preto, o logo é claro) e o "Meus pedidos" do FerraKora; o
 * resto, com a KazaKora de sempre. Beauty Kora continua como KazaKora.
 */
class MarcaDoEmail
{
    /** @return array{nome: string, logo: string, fundo_topo: string, site: string, meus_pedidos: string} */
    public static function doPedido(?Order $order): array
    {
        if ($order?->site_origem === Order::SITE_FERRAKORA) {
            $site = rtrim((string) config('services.ferrakora.url'), '/');

            return [
                'nome' => 'FerraKora',
                'logo' => url('/images/marca/ferrakora-email.png'),
                'fundo_topo' => '#0b0b0b',
                'site' => $site,
                'meus_pedidos' => $site.'/meus-pedidos',
            ];
        }

        return self::kazakora();
    }

    /** @return array{nome: string, logo: string, fundo_topo: string, site: string, meus_pedidos: string} */
    public static function kazakora(): array
    {
        return [
            'nome' => 'KazaKora',
            'logo' => Marca::urlAbsoluta('logo_nav'),
            'fundo_topo' => '#ffffff',
            'site' => url('/'),
            'meus_pedidos' => route('pedidos.meus'),
        ];
    }
}

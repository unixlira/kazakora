<?php

namespace App\Support;

use App\Modules\Checkout\Models\Order;

/**
 * Marca dos e-mails ao comprador (pedido do Lira 2026-10-15: FerraKora
 * separado da KazaKora). Pedido feito no site FerraKora sai com o nome, o
 * logo (topo preto, o logo é claro) e o "Meus pedidos" do FerraKora; o
 * resto, com a KazaKora de sempre. Beauty Kora continua como KazaKora.
 *
 * `pedido` (2026-10-16, pedido do Lira): link para o cliente ver o detalhe
 * do pedido no site (área do cliente, /pedidos/{id}, pede login). No
 * FerraKora, que tem área própria, continua o "Meus pedidos" de lá.
 *
 * `rodape` e `whatsapp_link` (2026-10-28, pedido do Lira: e-mail da FerraKora
 * com tudo da FerraKora): nome do rodapé e WhatsApp de contato. Na FerraKora,
 * o mesmo número do site (src/lib/loja.js do ferrakora); na KazaKora, os de
 * config('loja.empresa').
 */
class MarcaDoEmail
{
    /** @return array{nome: string, logo: string, fundo_topo: string, site: string, meus_pedidos: string, pedido: string, rodape: string, whatsapp_link: string} */
    public static function doPedido(?Order $order): array
    {
        if ($order?->site_origem === Order::SITE_FERRAKORA) {
            return self::ferrakora();
        }

        return self::kazakora($order);
    }

    /** Também nos e-mails da conta FerraKora, que não têm pedido (nova senha). */
    /** @return array{nome: string, logo: string, fundo_topo: string, site: string, meus_pedidos: string, pedido: string, rodape: string, whatsapp_link: string} */
    public static function ferrakora(): array
    {
        $site = rtrim((string) config('services.ferrakora.url'), '/');

        return [
            'nome' => 'FerraKora',
            'logo' => url('/images/marca/ferrakora-email.png'),
            'fundo_topo' => '#0b0b0b',
            'site' => $site,
            'meus_pedidos' => $site.'/meus-pedidos',
            'pedido' => $site.'/meus-pedidos',
            'rodape' => 'FerraKora | Grupo AlphaKora',
            'whatsapp_link' => 'https://wa.me/5511927206026',
        ];
    }

    /** @return array{nome: string, logo: string, fundo_topo: string, site: string, meus_pedidos: string, pedido: string, rodape: string, whatsapp_link: string} */
    public static function kazakora(?Order $order = null): array
    {
        return [
            'nome' => 'KazaKora',
            'logo' => Marca::urlAbsoluta('logo_nav'),
            'fundo_topo' => '#ffffff',
            'site' => url('/'),
            'meus_pedidos' => route('pedidos.meus'),
            'pedido' => $order?->id ? route('pedidos.ver', $order->id) : route('pedidos.meus'),
            'rodape' => (string) config('loja.empresa.nome'),
            'whatsapp_link' => (string) config('loja.empresa.whatsapp_link'),
        ];
    }
}

<?php

namespace App\Modules\Notifications\Push;

use App\Modules\Checkout\Models\Order;

/**
 * Os dois apps de vendas instaláveis (pedido do Lira, 2026-11-19): um com o
 * logo da KazaKora, que só avisa venda da kazakora.com, e um com o logo da
 * FerraKora, que só avisa venda da ferrakora.com. No iPhone o ícone da
 * notificação é sempre o do app que a recebe, por isso são dois apps e não
 * um com dois tipos de aviso.
 *
 * Os arquivos de cada app (manifest, service worker, ícones, página de
 * ativação) moram em repositórios próprios (unixlira/kazakora-vendas-app e
 * unixlira/ferrakora-vendas-app) e o nginx do painel serve em /app/<app>/.
 * Venda de marketplace e da Beauty Kora não avisa.
 */
class AppsDeVendas
{
    public const KAZAKORA = 'kazakora';

    public const FERRAKORA = 'ferrakora';

    public const TODOS = [self::KAZAKORA, self::FERRAKORA];

    public const NOMES = [
        self::KAZAKORA => 'KazaKora',
        self::FERRAKORA => 'FerraKora',
    ];

    /** App que avisa a venda deste pedido, ou nulo (marketplace, Beauty Kora). */
    public static function doPedido(Order $order): ?string
    {
        if ($order->origin !== Order::ORIGIN_STORE) {
            return null;
        }

        return match ($order->site_origem) {
            null, '' => self::KAZAKORA,
            Order::SITE_FERRAKORA => self::FERRAKORA,
            default => null,
        };
    }

    /** Endereço de um arquivo do app (ícone, página), servido pelo painel. */
    public static function url(string $app, string $caminho = ''): string
    {
        return rtrim((string) config('services.webpush.painel_url'), '/')."/app/{$app}/".ltrim($caminho, '/');
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ConfirmAmazonShipment;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use Illuminate\Console\Command;

/**
 * Confirma na Amazon (SP-API) os envios que ficaram pendentes — o caso
 * principal é a conta ser conectada DEPOIS dos pedidos já postados (app em
 * aprovação na Amazon em 25/09/2026). Roda de hora em hora; sem SP-API
 * conectada, não faz nada.
 */
class ConfirmPendingAmazonShipments extends Command
{
    protected $signature = 'amazon:confirmar-envios {--dias=15 : Janela de pedidos a conferir}';

    protected $description = 'Confirma na Amazon, pela SP-API, os envios com pré-postagem dos Correios ainda não confirmados';

    public function handle(): int
    {
        if (! app(AmazonDriver::class)->isConfigured()) {
            $this->info('SP-API da Amazon não conectada — nada a confirmar.');

            return self::SUCCESS;
        }

        $pedidos = CorreiosPrePostagem::query()
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->whereNull('amazon_confirmado_em')
            ->whereNotNull('codigo_objeto')
            ->whereHas('order', fn ($query) => $query
                ->where('origin', Order::ORIGIN_AMAZON)
                ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED])
                ->where('created_at', '>=', now()->subDays((int) $this->option('dias'))))
            ->pluck('order_id')
            ->unique();

        foreach ($pedidos as $orderId) {
            ConfirmAmazonShipment::dispatch($orderId);
        }

        $this->info($pedidos->count().' envio(s) mandados confirmar na Amazon.');

        return self::SUCCESS;
    }
}

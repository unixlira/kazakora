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
 *
 * --corrigir-transportadora: reenvia, com carrierCode "Correios", o rastreio
 * dos pedidos já confirmados como "Other" (antes de 16/10/2026) e ainda a
 * caminho, pro comprador ganhar o link de rastreio. Só na mão.
 */
class ConfirmPendingAmazonShipments extends Command
{
    protected $signature = 'amazon:confirmar-envios {--dias=15 : Janela de pedidos a conferir}
        {--corrigir-transportadora : Reenvia o rastreio dos já confirmados, agora com a transportadora Correios}';

    protected $description = 'Confirma na Amazon, pela SP-API, os envios com pré-postagem dos Correios ainda não confirmados';

    public function handle(): int
    {
        if (! app(AmazonDriver::class)->isConfigured()) {
            $this->info('SP-API da Amazon não conectada — nada a confirmar.');

            return self::SUCCESS;
        }

        if ($this->option('corrigir-transportadora')) {
            return $this->corrigirTransportadora();
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

    private function corrigirTransportadora(): int
    {
        $pedidos = CorreiosPrePostagem::query()
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->whereNotNull('amazon_confirmado_em')
            ->whereNotNull('codigo_objeto')
            ->where('amazon_confirmado_em', '>=', now()->subDays((int) $this->option('dias')))
            ->whereHas('order', fn ($query) => $query
                ->where('origin', Order::ORIGIN_AMAZON)
                ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED]))
            ->pluck('order_id')
            ->unique();

        foreach ($pedidos as $orderId) {
            ConfirmAmazonShipment::dispatch($orderId, reenviar: true);
        }

        $this->info($pedidos->count().' rastreio(s) mandados reenviar à Amazon com a transportadora Correios.');

        return self::SUCCESS;
    }
}

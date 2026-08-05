<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\LabelFetchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fallback manual — o fluxo normal desde 2026-08-05 é orientado a evento
 * (CheckShipmentLabelJob, disparado por ChannelShippingService::confirm()
 * e por PokeMercadoLivreLabelChecksJob a cada webhook), não mais este
 * comando agendado (removido de routes/console.php a pedido do usuário).
 * Continua útil pra rodar manualmente numa varredura geral se algum envio
 * ficar pra trás por qualquer motivo.
 */
class PollChannelShippingLabels extends Command
{
    protected $signature = 'marketplace:poll-labels';

    protected $description = 'Consulta os canais por etiquetas de envio prontas para pedidos com frete já confirmado (fallback manual)';

    public function handle(LabelFetchService $service): int
    {
        $shipments = ChannelShipment::query()
            ->where('status', ChannelShipment::STATUS_CONFIRMED)
            ->with('order.items')
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('Nenhum envio aguardando etiqueta.');

            return self::SUCCESS;
        }

        foreach ($shipments as $shipment) {
            try {
                if ($service->attempt($shipment)) {
                    $this->info("Etiqueta baixada para o pedido #{$shipment->order_id}.");
                }
            } catch (Throwable $exception) {
                Log::error('marketplace.poll_labels.failed', ['shipment_id' => $shipment->id, 'message' => $exception->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}

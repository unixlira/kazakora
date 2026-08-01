<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Etapa 5 do pipeline venda→nota→envio→etiqueta: nenhum canal pesquisado
 * (Mercado Livre, Shopee) tem webhook dedicado a "etiqueta pronta" — os dois
 * exigem consultar o status periodicamente até virar disponível. Roda a
 * cada 5 min sobre os envios já confirmados que ainda não têm etiqueta.
 */
class PollChannelShippingLabels extends Command
{
    protected $signature = 'marketplace:poll-labels';

    protected $description = 'Consulta os canais por etiquetas de envio prontas para pedidos com frete já confirmado';

    public function handle(MarketplaceDriverManager $manager, OrderFulfillmentTimeline $timeline): int
    {
        $shipments = ChannelShipment::query()
            ->where('status', ChannelShipment::STATUS_CONFIRMED)
            ->with('order')
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('Nenhum envio aguardando etiqueta.');

            return self::SUCCESS;
        }

        foreach ($shipments as $shipment) {
            if (! $shipment->order) {
                continue;
            }

            try {
                $label = $manager->driver($shipment->channel)->fetchLabel($shipment->order);
            } catch (Throwable $exception) {
                Log::error('marketplace.poll_labels.failed', ['shipment_id' => $shipment->id, 'message' => $exception->getMessage()]);

                continue;
            }

            if (! $label['ready']) {
                continue;
            }

            $extension = str_contains((string) $label['content_type'], 'pdf') ? 'pdf' : 'bin';
            $path = "labels/{$shipment->order_id}/etiqueta-{$shipment->id}.{$extension}";
            Storage::disk('local')->put($path, $label['contents']);

            $shipment->update([
                'status' => ChannelShipment::STATUS_LABEL_READY,
                'label_path' => $path,
                'label_ready_at' => now(),
            ]);

            $timeline->record($shipment->order, OrderFulfillmentEvent::STEP_LABEL_GENERATED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Etiqueta baixada do canal');

            PrintJob::query()->firstOrCreate(
                ['order_id' => $shipment->order_id],
                ['label_path' => $path, 'status' => PrintJob::STATUS_QUEUED],
            );

            $this->info("Etiqueta baixada para o pedido #{$shipment->order_id}.");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Núcleo de "consultar o canal e, se a etiqueta já estiver pronta,
 * baixar/gravar/registrar" — extraído de PollChannelShippingLabels
 * (2026-08-05) pra ser reaproveitado pelo fluxo orientado a evento
 * (CheckShipmentLabelJob), que é quem dirige o pipeline de etiqueta desde
 * então. O comando de polling continua existindo como fallback manual, mas
 * não roda mais agendado — ver routes/console.php.
 */
class LabelFetchService
{
    public function __construct(
        private readonly MarketplaceDriverManager $manager,
        private readonly OrderFulfillmentTimeline $timeline,
        private readonly LabelProcessingService $processor,
    ) {
    }

    /**
     * @return bool true se a etiqueta ficou pronta e foi gravada agora.
     *              false se o canal ainda não liberou — chamador decide o
     *              que fazer (tentar de novo, esperar).
     */
    public function attempt(ChannelShipment $shipment): bool
    {
        if (! $shipment->order) {
            return false;
        }

        $label = $this->manager->driver($shipment->channel)->fetchLabel($shipment->order);

        if (! $label['ready']) {
            return false;
        }

        $isPdf = str_contains((string) $label['content_type'], 'pdf');
        $contents = $label['contents'];

        // Sobrepõe a lista de produtos na etiqueta, igual já validado
        // manualmente na tela de teste de impressão — só é possível pra
        // etiqueta em PDF; se o overlay falhar por qualquer motivo, ainda
        // imprime a etiqueta crua em vez de travar o pedido por causa disso.
        if ($isPdf) {
            try {
                $productNames = $shipment->order->items->map(function ($item) {
                    return $item->quantity > 1
                        ? "{$item->quantity}x {$item->product_name}"
                        : $item->product_name;
                })->all();

                $contents = $this->processor->overlayProductList($contents, $productNames);
            } catch (Throwable $exception) {
                Log::warning('marketplace.label_fetch.overlay_failed', ['shipment_id' => $shipment->id, 'message' => $exception->getMessage()]);
            }
        }

        $extension = $isPdf ? 'pdf' : 'bin';
        $path = "labels/{$shipment->order_id}/etiqueta-{$shipment->id}.{$extension}";
        Storage::disk('local')->put($path, $contents);

        $shipment->update([
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'label_path' => $path,
            'label_ready_at' => now(),
        ]);

        $this->timeline->record($shipment->order, OrderFulfillmentEvent::STEP_LABEL_GENERATED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Etiqueta baixada do canal');

        PrintJob::query()->firstOrCreate(
            ['order_id' => $shipment->order_id],
            ['label_path' => $path, 'status' => PrintJob::STATUS_QUEUED],
        );

        return true;
    }
}

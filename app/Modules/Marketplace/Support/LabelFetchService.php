<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

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

        $contents = $label['contents'];

        // Achado real 2026-08-07 (pedidos #180/#181/#182 travados na
        // impressão física): a Shopee devolve um ZIP (assinatura real
        // "PK\x03\x04", confirmado nos bytes) contendo um
        // "thermal_zpl_shipping_label.txt" — nunca um PDF direto, mesmo
        // pedindo shipping_document_type=THERMAL_AIR_WAYBILL.
        // content_type vinha "application/force-download" (inútil pra
        // decidir), então a checagem antiga (str_contains content_type,
        // 'pdf') sempre dava falso e o ZIP cru ia direto pro SumatraPDF do
        // KoraSync, que falhava sempre (não é um PDF válido). Descompacta
        // primeiro (se for zip), depois converte o ZPL extraído pra PDF via
        // LabelProcessingService::convertZplToPdf() (já existia, usado só
        // na tela de teste manual).
        if (str_starts_with($contents, "PK\x03\x04")) {
            $contents = $this->extractZplFromZip($contents);
        }

        // A etiqueta real da Shopee começa com "~DG" (comando ZPL de
        // download de imagem — a etiqueta é um bitmap embutido, ver
        // LabelProcessingService) ANTES do bloco "^XA...^XZ", não direto
        // com "^XA" — checa a presença em vez de exigir como primeiro
        // caractere.
        if (str_contains($contents, '^XA')) {
            $contents = $this->processor->convertZplToPdf($contents);
        }

        $isPdf = str_starts_with($contents, '%PDF-');

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

    /**
     * O zip da Shopee tem um único arquivo de verdade dentro
     * (thermal_zpl_shipping_label.txt, confirmado ao vivo) — pega o
     * primeiro/único entry em vez de fixar esse nome exato, que pode variar
     * por conta/idioma sem aviso.
     */
    private function extractZplFromZip(string $zipContents): string
    {
        $tempZipPath = tempnam(sys_get_temp_dir(), 'shopee_label_').'.zip';
        file_put_contents($tempZipPath, $zipContents);

        try {
            $zip = new ZipArchive();

            if ($zip->open($tempZipPath) !== true) {
                throw new RuntimeException('Não foi possível abrir o zip da etiqueta da Shopee.');
            }

            if ($zip->numFiles < 1) {
                throw new RuntimeException('Zip da etiqueta da Shopee veio vazio.');
            }

            $entryName = $zip->getNameIndex(0);
            $extracted = $zip->getFromName($entryName);
            $zip->close();

            if ($extracted === false) {
                throw new RuntimeException("Não foi possível extrair \"{$entryName}\" do zip da etiqueta.");
            }

            return $extracted;
        } finally {
            @unlink($tempZipPath);
        }
    }
}

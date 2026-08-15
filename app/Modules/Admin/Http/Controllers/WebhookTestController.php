<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\LabelProcessingService;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Modules\Marketplace\Support\WebhookTestFixtures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Simula uma venda chegando de um marketplace, pra testar o pipeline real
 * (webhook -> pedido -> estoque -> etiqueta -> KoraSync) sem precisar
 * esperar uma venda de verdade. Usa dados fake (WebhookTestFixtures), mas
 * passa pelo caminho de código real (OrderImportService::importNormalized())
 * a partir daí — pedido, itens e débito de estoque são reais.
 *
 * A única etapa que não pode ser real é a confirmação de envio: o pedido
 * fake não existe em lugar nenhum no marketplace de verdade, então chamar a
 * API real (ConfirmChannelShippingJob -> driver) só devolveria erro —
 * essa etapa é simulada diretamente aqui (ver store()).
 */
class WebhookTestController extends Controller
{
    public function create(): Response
    {
        $channels = collect(WebhookTestFixtures::CHANNELS)->map(fn (string $name, string $channel) => [
            'value' => $channel,
            'label' => $name,
            'payload' => WebhookTestFixtures::raw($channel),
        ])->values();

        return Inertia::render('Admin/Impressoes/TesteWebhook', [
            'channels' => $channels,
        ]);
    }

    public function store(
        Request $request,
        OrderImportService $importService,
        OrderFulfillmentTimeline $timeline,
        LabelProcessingService $processor,
    ): RedirectResponse {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(array_keys(WebhookTestFixtures::CHANNELS))],
        ]);

        $channel = $validated['channel'];
        $raw = WebhookTestFixtures::raw($channel);
        $data = WebhookTestFixtures::normalize($channel, $raw);

        try {
            // dispatchShippingConfirmation: false — ver docblock da classe.
            $order = $importService->importNormalized($channel, $data, dispatchShippingConfirmation: false);

            $this->simulateShippingAndLabel($order, $channel, $data, $timeline, $processor);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Falha ao simular o webhook: '.$exception->getMessage());
        }

        return redirect()->route('admin.pedidos.exibir', $order)
            ->with('success', "Webhook de teste ({$channel}) processado — pedido #{$order->id} criado, etiqueta gerada, deve aparecer no KoraSync em instantes.");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function simulateShippingAndLabel(Order $order, string $channel, array $data, OrderFulfillmentTimeline $timeline, LabelProcessingService $processor): void
    {
        $shippingMethod = $data['simulated_shipping_method'] ?? 'drop_off';

        $shipment = ChannelShipment::query()->updateOrCreate(
            ['order_id' => $order->id, 'channel' => $channel],
            [
                'external_shipment_id' => $data['external_shipment_id'] ?? null,
                'shipping_method' => $shippingMethod,
                'status' => ChannelShipment::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ],
        );

        $timeline->record($order, OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED, OrderFulfillmentEvent::STATUS_SUCCESS, "Método: {$shippingMethod} (simulado — teste de webhook)");

        $pdf = new Fpdi();
        $pdf->AddPage('P', [101.6, 152.4]);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetXY(10, 10);
        $pdf->Cell(0, 10, 'ETIQUETA DE TESTE - '.mb_convert_encoding(WebhookTestFixtures::CHANNELS[$channel], 'ISO-8859-1', 'UTF-8'), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetXY(10, 25);
        $pdf->Cell(0, 8, mb_convert_encoding('Pedido: '.$order->external_order_id, 'ISO-8859-1', 'UTF-8'), 0, 1);
        $pdf->SetXY(10, 33);
        $pdf->Cell(0, 8, mb_convert_encoding('Destinatario: '.$order->shipping_name, 'ISO-8859-1', 'UTF-8'), 0, 1);
        $rawPdf = $pdf->Output('S');

        // Mesmo formato do fluxo real (LabelFetchService::attempt()) — SKU|QTD=N,
        // pedido explícito 2026-08-15.
        $order->loadMissing('items.product');
        $declarationTokens = $order->items->map(function ($item) {
            $sku = $item->product?->sku ?: $item->product_name;

            return "{$sku}|QTD={$item->quantity}";
        })->all();

        $finalPdf = $processor->appendDeclarationPage($rawPdf, $declarationTokens);

        $path = "labels/{$order->id}/etiqueta-teste-webhook-".now()->timestamp.'.pdf';
        Storage::disk('local')->put($path, $finalPdf);

        $shipment->update([
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'label_path' => $path,
            'label_ready_at' => now(),
        ]);

        $timeline->record($order, OrderFulfillmentEvent::STEP_LABEL_GENERATED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Etiqueta gerada (simulado — teste de webhook)');

        PrintJob::query()->firstOrCreate(
            ['order_id' => $order->id],
            ['channel' => $channel, 'is_thank_you' => false, 'label_path' => $path, 'status' => PrintJob::STATUS_QUEUED],
        );
    }
}

<?php

namespace App\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Services\Bling\BlingOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Avisa a Amazon, via Bling, que o pedido foi postado — pedido explícito
 * 2026-09-25: "assim que for gerado o QR code já sai o código de postagem,
 * então já atualiza o pedido lá na Amazon". Grava o código de rastreio dos
 * Correios no pedido do Bling e muda a situação dele (ver
 * BlingOrderService::informarEnvio()); a integração nativa do Bling com a
 * Amazon leva isso pra lá.
 *
 * Só depois que a etiqueta já está pronta aqui: se a situação nova do
 * Bling for mapeada como "enviado" (BLING_SITUACOES_ENVIADO), o webhook
 * dela vira STATUS_SHIPPED e pedido que não está mais PAGO nunca imprime —
 * informar antes da etiqueta sair podia deixar o pacote sem etiqueta.
 *
 * Idempotente: CorreiosPrePostagem::bling_informado_em marca o que já foi.
 * Fila "default" (mesmo motivo de ProcessBlingOrderWebhook).
 */
class InformAmazonShipmentToBling implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public function __construct(public readonly int $orderId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 120, 300, 600, 1800];
    }

    public function handle(CorreiosAutoShipping $correios, BlingOrderService $blingOrders, OrderFulfillmentTimeline $timeline): void
    {
        if (! config('services.bling.amazon_envio.informar')) {
            return;
        }

        $order = Order::with('invoice', 'channelShipment')->find($this->orderId);

        if (! $order || $order->origin !== Order::ORIGIN_AMAZON || ! app(AmazonDriver::class)->viaBling()) {
            return;
        }

        $prePostagem = $correios->geradaPara($order);

        if (! $prePostagem || $prePostagem->bling_informado_em || ! $prePostagem->codigo_objeto) {
            return;
        }

        $etiquetaPronta = in_array($order->channelShipment?->status, [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED], true);

        if (! $etiquetaPronta) {
            $this->release(60);

            return;
        }

        $pedidoBling = $blingOrders->findByOrderNumber((string) $order->external_order_id, $blingOrders->amazonLojaId());

        if (! $pedidoBling) {
            throw new \RuntimeException("Pedido Amazon {$order->external_order_id} não encontrado no Bling pra informar o envio.");
        }

        $blingOrders->informarEnvio(
            (int) $pedidoBling['id'],
            $prePostagem->codigo_objeto,
            'Correios '.$prePostagem->service_label,
            (int) ($pedidoBling['notaFiscal']['id'] ?? 0) ?: null,
        );

        $prePostagem->update(['bling_informado_em' => now()]);

        $timeline->record($order, OrderFulfillmentEvent::STEP_HANDED_TO_CARRIER, OrderFulfillmentEvent::STATUS_SUCCESS, "Rastreio {$prePostagem->codigo_objeto} enviado ao Bling (Amazon).");
    }

    public function failed(?Throwable $exception): void
    {
        $order = Order::find($this->orderId);

        if ($order) {
            app(OrderFulfillmentTimeline::class)->record($order, OrderFulfillmentEvent::STEP_HANDED_TO_CARRIER, OrderFulfillmentEvent::STATUS_FAILED, 'Não deu pra informar o rastreio no Bling/Amazon: '.$exception?->getMessage());
        }
    }
}

<?php

namespace App\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Services\Correios\CorreiosPrePostagemService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Confirma o envio do pedido direto na Amazon (SP-API shipmentConfirmation)
 * assim que a etiqueta dos Correios está pronta — pedido explícito
 * 2026-09-25. O Bling não repassa rastreio pra Amazon, então sem isto o
 * pedido ficava "não enviado" lá até alguém confirmar à mão.
 *
 * Sem SP-API conectada (app ainda em aprovação na Amazon), registra no
 * pedido que a confirmação é manual e para — o comando
 * amazon:confirmar-envios, de hora em hora, confirma os pendentes assim que
 * a conta for conectada.
 *
 * Idempotente: CorreiosPrePostagem::amazon_confirmado_em marca o que já foi.
 */
class ConfirmAmazonShipment implements ShouldQueue, ShouldBeUnique
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
        return [60, 120, 300, 900, 1800];
    }

    public function handle(CorreiosAutoShipping $correios, OrderFulfillmentTimeline $timeline): void
    {
        $order = Order::with('channelShipment')->find($this->orderId);

        if (! $order || $order->origin !== Order::ORIGIN_AMAZON) {
            return;
        }

        $prePostagem = $correios->geradaPara($order);

        if (! $prePostagem || $prePostagem->amazon_confirmado_em || ! $prePostagem->codigo_objeto) {
            return;
        }

        $driver = app(AmazonDriver::class);

        if (! $driver->isConfigured()) {
            $timeline->record($order, OrderFulfillmentEvent::STEP_HANDED_TO_CARRIER, OrderFulfillmentEvent::STATUS_FAILED, "Amazon ainda não conectada pela SP-API — confirme o envio no Seller Central: Correios, {$prePostagem->codigo_objeto}. Sai sozinho quando a conta for conectada.");

            return;
        }

        if (! in_array($order->channelShipment?->status, [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED], true)) {
            $this->release(60);

            return;
        }

        $servico = $prePostagem->service_code === CorreiosPrePostagemService::SERVICO_SEDEX ? 'SEDEX' : 'PAC';

        $driver->confirmarEnvio($order, $prePostagem->codigo_objeto, $servico);

        $prePostagem->update(['amazon_confirmado_em' => now()]);

        $timeline->record($order, OrderFulfillmentEvent::STEP_HANDED_TO_CARRIER, OrderFulfillmentEvent::STATUS_SUCCESS, "Envio confirmado na Amazon: Correios {$servico}, {$prePostagem->codigo_objeto}.");
    }

    public function failed(?Throwable $exception): void
    {
        $order = Order::find($this->orderId);

        if ($order) {
            app(OrderFulfillmentTimeline::class)->record($order, OrderFulfillmentEvent::STEP_HANDED_TO_CARRIER, OrderFulfillmentEvent::STATUS_FAILED, 'A Amazon recusou a confirmação de envio: '.$exception?->getMessage());
        }
    }
}

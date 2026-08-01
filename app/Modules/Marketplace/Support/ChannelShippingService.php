<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use Throwable;

/**
 * Etapa 4: confirma/consulta o método de envio no canal (a decisão em si —
 * Flex x padrão no Mercado Livre, drop-off na Shopee — é feita pelo próprio
 * canal ou já decidida automaticamente; ver MarketplaceChannelDriver::confirmShipping()).
 */
class ChannelShippingService
{
    public function __construct(
        private readonly MarketplaceDriverManager $manager,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {
    }

    public function confirm(Order $order): ChannelShipment
    {
        $shipment = ChannelShipment::query()->firstOrCreate(
            ['order_id' => $order->id, 'channel' => $order->origin],
            ['status' => ChannelShipment::STATUS_PENDING],
        );

        try {
            $result = $this->manager->driver($order->origin)->confirmShipping($order);
        } catch (Throwable $exception) {
            $shipment->update(['status' => ChannelShipment::STATUS_ERROR, 'error_message' => $exception->getMessage()]);
            $this->timeline->record($order, OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED, OrderFulfillmentEvent::STATUS_FAILED, $exception->getMessage());

            throw $exception;
        }

        $shipment->update([
            'external_shipment_id' => $result['external_shipment_id'] ?? $shipment->external_shipment_id,
            'shipping_method' => $result['shipping_method'],
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        $this->timeline->record($order, OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED, OrderFulfillmentEvent::STATUS_SUCCESS, "Método: {$result['shipping_method']}");

        return $shipment;
    }
}

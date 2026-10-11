<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'origin' => $this->origin,
            'external_order_id' => $this->external_order_id,
            'shipping_name' => $this->shipping_name,
            'shipping_phone' => $this->shipping_phone,
            'shipping_email' => $this->shipping_email,
            'shipping_zip' => $this->shipping_zip,
            'shipping_street' => $this->shipping_street,
            'shipping_number' => $this->shipping_number,
            'shipping_complement' => $this->shipping_complement,
            'shipping_neighborhood' => $this->shipping_neighborhood,
            'shipping_city' => $this->shipping_city,
            'shipping_state' => $this->shipping_state,
            'subtotal' => (float) $this->subtotal,
            'shipping_cost' => (float) $this->shipping_cost,
            'discount_amount' => (float) $this->discount_amount,
            'total' => (float) $this->total,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_price' => (float) $item->product_price,
                'quantity' => $item->quantity,
                'subtotal' => (float) $item->subtotal,
            ])),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? new InvoiceResource($this->invoice) : null),
            'shipment' => $this->whenLoaded('channelShipment', fn () => $this->channelShipment ? new ChannelShipmentResource($this->channelShipment) : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

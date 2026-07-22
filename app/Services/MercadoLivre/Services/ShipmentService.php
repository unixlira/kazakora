<?php

namespace App\Services\MercadoLivre\Services;

use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Log;

class ShipmentService
{
    public function __construct(private readonly MercadoLivreClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function getShipment(string $shipmentId): array
    {
        return $this->client->get("shipments/{$shipmentId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function getTrackingInfo(string $shipmentId): array
    {
        return $this->client->get("shipments/{$shipmentId}/history");
    }

    /**
     * @return array<string, mixed>
     */
    public function updateShipmentStatus(string $shipmentId, string $status): array
    {
        return $this->client->put("shipments/{$shipmentId}", ['status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.shipments', $payload);
    }
}

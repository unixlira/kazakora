<?php

namespace App\Services\MercadoLivre\Webhooks;

use App\Services\MercadoLivre\Services\MessageService;
use App\Services\MercadoLivre\Services\OrderService;
use App\Services\MercadoLivre\Services\ProductService;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Support\Facades\Log;

class WebhookHandler
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ProductService $products,
        private readonly MessageService $messages,
        private readonly ShipmentService $shipments,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $topic = $payload['topic'] ?? null;

        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.received', $payload);

        match ($topic) {
            'orders', 'orders_v2' => $this->orders->processWebhook($payload),
            'items' => $this->products->processWebhook($payload),
            'prices' => $this->products->processPriceUpdate($payload),
            'messages' => $this->messages->processWebhook($payload),
            'shipments' => $this->shipments->processWebhook($payload),
            default => Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.unhandled_topic', $payload),
        };
    }
}

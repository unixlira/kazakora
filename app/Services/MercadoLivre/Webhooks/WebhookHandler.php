<?php

namespace App\Services\MercadoLivre\Webhooks;

use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Services\MercadoLivre\Services\ClaimService;
use App\Services\MercadoLivre\Services\MessageService;
use App\Services\MercadoLivre\Services\OrderService;
use App\Services\MercadoLivre\Services\ProductService;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookHandler
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ProductService $products,
        private readonly MessageService $messages,
        private readonly ShipmentService $shipments,
        private readonly ClaimService $claims,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, int $webhookLogId): void
    {
        $topic = $payload['topic'] ?? null;

        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.received', $payload);

        if (! in_array($topic, ['orders', 'orders_v2', 'items', 'prices', 'messages', 'shipments', 'post_purchase'], true)) {
            Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.unhandled_topic', $payload);

            ChannelWebhookLog::whereKey($webhookLogId)->update(['status' => ChannelWebhookLog::STATUS_IGNORED]);

            return;
        }

        try {
            match ($topic) {
                'orders', 'orders_v2' => $this->orders->processWebhook($payload),
                'items' => $this->products->processWebhook($payload),
                'prices' => $this->products->processPriceUpdate($payload),
                'messages' => $this->messages->processWebhook($payload),
                'shipments' => $this->shipments->processWebhook($payload),
                'post_purchase' => $this->claims->processWebhook($payload),
            };

            ChannelWebhookLog::whereKey($webhookLogId)->update(['status' => ChannelWebhookLog::STATUS_PROCESSED]);
        } catch (Throwable $exception) {
            Log::channel(config('mercadolivre.log_channel'))->error('mercadolivre.webhook.processing_failed', [
                'topic' => $topic,
                'message' => $exception->getMessage(),
            ]);

            ChannelWebhookLog::whereKey($webhookLogId)->update([
                'status' => ChannelWebhookLog::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

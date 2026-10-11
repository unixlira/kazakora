<?php

namespace App\Services\Shopee\Webhooks;

use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookHandler
{
    public function __construct(private readonly OrderImportService $importer) {}

    /**
     * Não deu pra confirmar ao vivo o nome exato do campo do order_sn no
     * payload de push (open.shopee.com inacessível nesta sessão — ver
     * plano), então procura nas variantes mais prováveis em vez de assumir
     * uma única. Se o "Test Push" real trouxer um payload com um nome
     * diferente, é só adicionar aqui.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, int $webhookLogId): void
    {
        $orderSn = $payload['data']['ordersn']
            ?? $payload['data']['order_sn']
            ?? $payload['ordersn']
            ?? $payload['order_sn']
            ?? null;

        if (! $orderSn) {
            Log::channel('shopee')->info('shopee.webhook.no_order_sn', $payload);

            ChannelWebhookLog::whereKey($webhookLogId)->update(['status' => ChannelWebhookLog::STATUS_IGNORED]);

            return;
        }

        try {
            $this->importer->import(MarketplaceAccount::CHANNEL_SHOPEE, (string) $orderSn);

            ChannelWebhookLog::whereKey($webhookLogId)->update(['status' => ChannelWebhookLog::STATUS_PROCESSED]);
        } catch (Throwable $exception) {
            Log::channel('shopee')->error('shopee.webhook.import_failed', [
                'order_sn' => $orderSn,
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

<?php

namespace App\Services\MercadoLivre\Services;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\MercadoLivre\DTOs\OrderDTO;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderService
{
    public function __construct(
        private readonly MercadoLivreClient $client,
        private readonly OrderImportService $importer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listRecentOrders(int $mlUserId): array
    {
        return $this->client->get('orders/search/recent', ['seller' => $mlUserId]);
    }

    public function getOrder(string $orderId): OrderDTO
    {
        $order = $this->client->get("orders/{$orderId}");

        return new OrderDTO(
            id: (int) $order['id'],
            status: $order['status'],
            total_amount: (float) $order['total_amount'],
            currency_id: $order['currency_id'],
            date_created: $order['date_created'],
            buyer: $order['buyer'] ?? [],
            order_items: $order['order_items'] ?? [],
            shipping: $order['shipping'] ?? [],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderItems(string $orderId): array
    {
        return $this->client->get("orders/{$orderId}")['order_items'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateOrderStatus(string $orderId, string $status): array
    {
        return $this->client->put("orders/{$orderId}", ['status' => $status]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getShippingInfo(string $shipmentId): array
    {
        return $this->client->get("shipments/{$shipmentId}");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.orders', $payload);

        if (! preg_match('#/orders/(\d+)#', $payload['resource'] ?? '', $matches)) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.webhook.orders.unparseable_resource', $payload);

            return;
        }

        try {
            $this->importer->import(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $matches[1]);
        } catch (Throwable $exception) {
            Log::channel(config('mercadolivre.log_channel'))->error('mercadolivre.webhook.orders.import_failed', [
                'order_id' => $matches[1],
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

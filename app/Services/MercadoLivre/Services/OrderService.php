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

    /**
     * Todos os pedidos do vendedor, não só os "recentes" — usado pro
     * backfill (App\Console\Commands\SyncMercadoLivreOrders, 2026-08-06)
     * que traz pro banco local qualquer venda que nunca chegou por webhook
     * (ex: cron parado, app desconectado num período). orders/search
     * pagina por offset/limit real (confirmado ao vivo).
     *
     * @return array<int, string>
     */
    public function listAllOrderIds(int $mlUserId): array
    {
        $ids = [];
        $offset = 0;
        $limit = 50;

        do {
            $page = $this->client->get('orders/search', [
                'seller' => $mlUserId,
                'offset' => $offset,
                'limit' => $limit,
            ]);

            $results = $page['results'] ?? [];

            foreach ($results as $order) {
                if (isset($order['id'])) {
                    $ids[] = (string) $order['id'];
                }
            }

            $total = (int) ($page['paging']['total'] ?? 0);
            $offset += $limit;
        } while ($offset < $total);

        return $ids;
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
     * CPF/CNPJ real do comprador — não vem no payload normal do pedido
     * (confirmado, só tem nome/endereço ali), mas a Mercado Livre mantém
     * esse endpoint dedicado especificamente pra emissão de nota fiscal.
     * Retorna null (nunca lança) se o endpoint falhar ou não trouxer
     * documento — quem chama decide o que fazer com "sem CPF disponível",
     * não é motivo pra derrubar a importação do pedido inteira.
     */
    public function getBuyerDocument(string $orderId): ?string
    {
        try {
            $response = $this->client->get("orders/{$orderId}/billing_info");
        } catch (Throwable $exception) {
            Log::channel('mercadolivre')->warning('mercadolivre.billing_info_failed', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $docNumber = $response['billing_info']['doc_number'] ?? null;

        return $docNumber ? preg_replace('/\D/', '', (string) $docNumber) : null;
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

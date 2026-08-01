<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Shopee — API docs: https://open.shopee.com (Open Platform, partner approval required).
 *
 * OAuth2 authorization + HMAC-signed requests. Product publishing goes
 * through v2.product.add_item, images are uploaded first via
 * v2.media_space.upload_image and referenced by image id, stock through
 * v2.product.update_stock.
 */
class ShopeeDriver extends AbstractMarketplaceDriver
{
    public function __construct(private readonly ShopeeClient $client) {}

    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_SHOPEE;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // TODO: upload $product->images via v2.media_space.upload_image,
        // then call v2.product.add_item with the returned image ids and
        // the category-specific attributes from $listing->attributes.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call v2.product.update_stock with $product->stock.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call v2.product.unlist_item.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    public function importOrder(string $externalOrderId): array
    {
        $this->ensureConfigured();

        // TODO: call v2.order.get_order_detail and normalize the response
        // to the shape declared in MarketplaceChannelDriver::importOrder().
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    /**
     * `v2.order.upload_invoice_doc` (POST /api/v2/order/upload_invoice_doc),
     * multipart: order_sn, file_type=4 (XML), file. Aceita o XML da NF-e
     * direto, sem precisar do DANFE. Endpoint específico BR (e PH),
     * confirmado no schema oficial da Open Platform.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        if (! $invoice->xml_path) {
            throw new RuntimeException('Nota fiscal sem XML disponível para envio.');
        }

        $xml = Storage::disk('local')->get($invoice->xml_path);
        $filename = "nfe-{$invoice->chave_acesso}.xml";

        try {
            $response = $this->client->postMultipart(
                '/api/v2/order/upload_invoice_doc',
                ['order_sn' => $order->external_order_id, 'file_type' => 4],
                ['contents' => $xml, 'filename' => $filename],
            );

            return ['status' => 'sent', 'external_reference' => $order->external_order_id, 'response' => $response];
        } catch (ShopeeException $exception) {
            return ['status' => 'error', 'external_reference' => null, 'response' => ['error' => $exception->getMessage(), 'context' => $exception->context]];
        }
    }

    /**
     * get_shipping_parameter diz quais métodos o pedido suporta e, pro
     * dropoff, devolve os branch_id disponíveis — usa o primeiro (conta com
     * um único ponto de coleta configurado não precisa de escolha real).
     * ship_order confirma de fato o método.
     */
    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        $params = $this->client->get('/api/v2/logistics/get_shipping_parameter', [
            'order_sn' => $order->external_order_id,
        ]);

        $branchId = $params['response']['info_needed']['dropoff']['branch_list'][0]['branch_id'] ?? null;

        if (! $branchId) {
            throw new RuntimeException("Pedido {$order->external_order_id}: nenhum branch_id de dropoff disponível na Shopee.");
        }

        $result = $this->client->post('/api/v2/logistics/ship_order', [
            'order_sn' => $order->external_order_id,
            'dropoff' => ['branch_id' => $branchId],
        ]);

        return [
            'external_shipment_id' => $order->external_order_id,
            'shipping_method' => 'drop_off',
            'status' => empty($result['error']) ? 'confirmed' : 'error',
        ];
    }

    /**
     * 3 chamadas: create_shipping_document cria a tarefa (idempotente por
     * order_sn) → get_shipping_document_result até status=READY
     * (processamento assíncrono do lado da Shopee) → download_shipping_document
     * devolve o binário. `ready: false` enquanto ainda está PROCESSING.
     */
    public function fetchLabel(Order $order): array
    {
        $this->ensureConfigured();

        $this->client->post('/api/v2/logistics/create_shipping_document', [
            'order_list' => [['order_sn' => $order->external_order_id]],
        ]);

        $result = $this->client->post('/api/v2/logistics/get_shipping_document_result', [
            'order_list' => [['order_sn' => $order->external_order_id]],
        ]);

        $status = $result['response']['result_list'][0]['status'] ?? null;

        if ($status !== 'READY') {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $label = $this->client->getBinary('/api/v2/logistics/download_shipping_document', [
            'order_list' => [['order_sn' => $order->external_order_id]],
            'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
        ]);

        return ['ready' => true, 'contents' => $label['contents'], 'content_type' => $label['content_type']];
    }
}

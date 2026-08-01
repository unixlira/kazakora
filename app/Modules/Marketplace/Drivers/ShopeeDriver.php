<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;

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
     * Pesquisado e confirmado (2026-08-01) via schemas oficiais da Open
     * Platform, mas SEM conta/credencial Shopee pra testar ao vivo —
     * endpoint e formato têm alta confiança, mas nunca foram exercitados de
     * verdade. `v2.order.upload_invoice_doc`
     * (POST /api/v2/order/upload_invoice_doc), multipart, campos:
     * order_sn, file_type (1=PDF, 2=JPEG, 3=PNG, 4=XML), file (máx 1MB).
     * Aceita o XML da NF-e (file_type=4) direto — não precisa do DANFE.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        // TODO: multipart POST pra v2.order.upload_invoice_doc com
        // order_sn=$order->external_order_id, file_type=4, file=XML de
        // Storage::disk('local')->get($invoice->xml_path).
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    /**
     * Fluxo confirmado: v2.logistics.get_shipping_parameter (order_sn) diz
     * se o pedido suporta dropoff/pickup/non_integrated e, pro dropoff,
     * devolve os branch_id disponíveis — depois v2.logistics.ship_order
     * (order_sn + dropoff.branch_id) confirma de fato. Não é 100%
     * automático mesmo em dropoff: pode existir mais de um branch_id
     * configurado, exigindo escolha (ficaria "o único disponível" na
     * prática pra uma conta com um endereço de coleta só).
     */
    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        // TODO: get_shipping_parameter → escolher branch_id (dropoff) →
        // ship_order → normalizar retorno pro formato do contrato.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    /**
     * Fluxo confirmado, 3 chamadas: v2.logistics.create_shipping_document
     * (order_sn) cria a tarefa → v2.logistics.get_shipping_document_result
     * até status=READY (processamento assíncrono do lado da Shopee, por
     * isso o polling) → v2.logistics.download_shipping_document devolve o
     * arquivo (binário) direto no corpo da resposta.
     */
    public function fetchLabel(Order $order): array
    {
        $this->ensureConfigured();

        // TODO: create_shipping_document (idempotente por order_sn) →
        // get_shipping_document_result → se READY, download_shipping_document.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }
}

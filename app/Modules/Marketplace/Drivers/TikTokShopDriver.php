<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;

/**
 * TikTok Shop — API docs: https://partner.tiktokshop.com (Partner Center,
 * approval required for BR sellers).
 *
 * OAuth2 authorization + HMAC-signed requests. Product publishing goes
 * through /product/202309/products, images through
 * /product/202309/images/upload, stock through
 * /product/202309/prices/update or /inventory/update.
 */
class TikTokShopDriver extends AbstractMarketplaceDriver
{
    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_TIKTOK_SHOP;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // TODO: upload $product->images via /product/202309/images/upload,
        // then call /product/202309/products with the returned image ids
        // and the category-specific attributes from $listing->attributes.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call /product/202309/inventory/update with $product->stock.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call /product/202309/products/deactivate.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function importOrder(string $externalOrderId): array
    {
        $this->ensureConfigured();

        // TODO: call /order/202309/orders and normalize the response to
        // the shape declared in MarketplaceChannelDriver::importOrder().
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    /**
     * Confirmado (2026-08-01): TikTok Shop BR exige NF-e (XML, <10MB) via
     * "Gerenciar Pedidos" antes do pedido poder virar "Pronto para envio" —
     * existe uma página de doc dedicada "BR market - Updated API workflow
     * to support Order, Invoice and Warehouse", mas o conteúdo fica atrás de
     * login de parceiro aprovado — endpoint exato não confirmado ainda.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        // TODO: endpoint exato só visível com credencial de parceiro
        // aprovada — ver "BR market - Order, Invoice and Warehouse" no
        // Partner Center quando a conta existir.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        // TODO: Fulfillment API, fluxo geral (não confirmado em detalhe):
        // Get Shipping Provider → Create Package → Ship Package.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function fetchLabel(Order $order): array
    {
        $this->ensureConfigured();

        // TODO: "Get Package Shipping Document", pós Ship Package.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }
}

<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
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
}

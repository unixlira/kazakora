<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
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
}

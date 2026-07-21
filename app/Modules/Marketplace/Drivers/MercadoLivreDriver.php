<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;

/**
 * Mercado Livre — API docs: https://developers.mercadolivre.com.br
 *
 * OAuth2 authorization code flow. Product publishing goes through
 * POST /items, images through POST /pictures, stock through
 * PUT /items/{id} (available_quantity).
 */
class MercadoLivreDriver extends AbstractMarketplaceDriver
{
    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_MERCADO_LIVRE;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // TODO: POST https://api.mercadolibre.com/items with the mapped
        // category, price, available_quantity, pictures (from $product->images)
        // and the channel-specific attributes stored in $listing->attributes.
        throw new \RuntimeException('Integração com Mercado Livre ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: PUT https://api.mercadolibre.com/items/{$listing->external_id}
        // with ['available_quantity' => $product->stock].
        throw new \RuntimeException('Integração com Mercado Livre ainda não implementada.');
    }
}

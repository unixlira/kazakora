<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\DTOs\ProductDTO;
use App\Services\MercadoLivre\Services\ProductService;

/**
 * Mercado Livre — API docs: https://developers.mercadolivre.com.br
 *
 * Delegates the actual HTTP calls to App\Services\MercadoLivre\Services\ProductService,
 * which uses the OAuth token managed by MercadoLivreAuthService (see
 * app/Services/MercadoLivre). The channel-specific `category_id` (required by
 * the ML API, but meaningless to the other channels) comes from the JSON
 * attributes editor on the product's "Canais de venda" tab.
 */
class MercadoLivreDriver extends AbstractMarketplaceDriver
{
    public function __construct(private readonly ProductService $products) {}

    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_MERCADO_LIVRE;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        $dto = new ProductDTO(
            title: $product->name,
            category_id: $listing->attributes['category_id'] ?? '',
            price: (float) $product->final_price,
            available_quantity: $product->stock,
            description: $product->description,
            pictures: $product->images->map(fn ($image) => ['source' => $image->url])->all(),
            // Some ML categories require a "product family" name even for a
            // single, non-variant listing (confirmed live: HTTP 400
            // body.required_fields → "[family_name]" is missing). We don't
            // model product families, so just reuse the product's own name.
            family_name: $product->name,
        );

        $response = $this->products->createItem($dto);

        return (string) $response['id'];
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        $this->products->updateStock($listing->external_id, $product->stock);
    }
}

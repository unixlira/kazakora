<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\DTOs\ProductDTO;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
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

        // Already published: update the existing item instead of creating a
        // new one every time staff re-save the channel (confirmed live —
        // re-saving an already-published listing was creating a duplicate
        // item on Mercado Livre each time).
        if ($listing->external_id) {
            $this->products->updateItem($listing->external_id, [
                'price' => (float) $product->final_price,
                'available_quantity' => $product->stock,
            ]);

            return $listing->external_id;
        }

        $categoryId = $listing->attributes['category_id'] ?? '';
        $attributes = $this->buildAttributes($product);
        $pictures = $product->images->map(fn ($image) => ['source' => $image->url])->all();

        $dto = new ProductDTO(
            category_id: $categoryId,
            price: (float) $product->final_price,
            available_quantity: $product->stock,
            title: $product->name,
            description: $product->description,
            pictures: $pictures,
            attributes: $attributes,
        );

        try {
            $response = $this->products->createItem($dto);
        } catch (MercadoLivreException $exception) {
            if (! $this->requiresProductFamily($exception)) {
                throw $exception;
            }

            // Confirmed live: this category rejects a plain title and wants
            // the item grouped under a "product family" instead — ML then
            // derives the title from family_name + attributes itself, which
            // is why title must be omitted (sending both is also rejected).
            $retryDto = new ProductDTO(
                category_id: $categoryId,
                price: (float) $product->final_price,
                available_quantity: $product->stock,
                description: $product->description,
                pictures: $pictures,
                family_name: $product->name,
                attributes: $attributes,
            );

            $response = $this->products->createItem($retryDto);
        }

        return (string) $response['id'];
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        $this->products->updateStock($listing->external_id, $product->stock);
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        $this->products->closeItem($listing->external_id);
    }

    private function requiresProductFamily(MercadoLivreException $exception): bool
    {
        $cause = $exception->context['body']['cause'][0]['message'] ?? '';

        return str_contains($cause, 'family_name');
    }

    /**
     * @return array<int, array{id: string, value_name: string}>
     */
    private function buildAttributes(Product $product): array
    {
        $attributes = [];

        if ($product->brand) {
            $attributes[] = ['id' => 'BRAND', 'value_name' => $product->brand];
        }

        if ($product->model) {
            $attributes[] = ['id' => 'MODEL', 'value_name' => $product->model];
        }

        if ($product->color) {
            // Sent under both IDs: some categories require COLOR, others
            // require MATERIAL (confirmed live — this store's "cor" field is
            // sometimes really describing material, e.g. "Bambu"), and there
            // is no dedicated material field on Product to map separately.
            $attributes[] = ['id' => 'COLOR', 'value_name' => $product->color];
            $attributes[] = ['id' => 'MATERIAL', 'value_name' => $product->color];
        }

        return $attributes;
    }
}

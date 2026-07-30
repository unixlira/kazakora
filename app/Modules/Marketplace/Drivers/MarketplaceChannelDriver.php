<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\ProductChannelListing;

interface MarketplaceChannelDriver
{
    public function channel(): string;

    public function isConfigured(): bool;

    /**
     * Create or update the product listing on the marketplace, including
     * images, and return the marketplace's external listing id.
     */
    public function publishProduct(Product $product, ProductChannelListing $listing): string;

    public function updateStock(Product $product, ProductChannelListing $listing): void;

    /**
     * Take the listing down from the marketplace. Most marketplaces don't
     * support a true delete once a listing has ever gone live (order/review
     * history has to be preserved) — this closes/deactivates it instead.
     */
    public function unpublishProduct(ProductChannelListing $listing): void;
}

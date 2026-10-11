<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MercadoLivreListingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_mercado_livre_listings_with_product_data(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['name' => 'Produto teste', 'sku' => 'SKU-1', 'price' => 49.9, 'stock' => 10]);
        $product->channelListings()->create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => 'MLB123456',
        ]);

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/anuncios');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('listings.0.productName', 'Produto teste')
            ->where('listings.0.sku', 'SKU-1')
            ->where('listings.0.externalId', 'MLB123456')
            ->where('listings.0.externalUrl', 'https://produto.mercadolivre.com.br/MLB123456')
            ->where('listings.0.status', ProductChannelListing::STATUS_PUBLISHED)
            ->where('listings.0.price', '49.90'));
    }

    public function test_it_does_not_include_listings_from_other_channels(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();
        $product->channelListings()->create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => 'SHOPEE-1',
        ]);

        $response = $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/anuncios');

        $response->assertInertia(fn ($page) => $page->has('listings', 0));
    }
}

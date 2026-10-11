<?php

namespace Tests\Feature\Marketplace;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductChannelCategoryDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function connectMercadoLivre(): void
    {
        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);

        MarketplaceAccount::query()->create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456789',
            'connected_at' => now(),
        ]);
    }

    public function test_publishing_without_a_category_id_auto_discovers_one(): void
    {
        $this->connectMercadoLivre();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['name' => 'Notebook Dell Inspiron 15']);

        Http::fake([
            'https://api.mercadolibre.com/sites/MLB/domain_discovery/search*' => Http::response([
                ['domain_id' => 'MLB-NOTEBOOKS', 'category_id' => 'MLB1652', 'category_name' => 'Notebooks'],
            ]),
            'https://api.mercadolibre.com/items' => Http::response(['id' => 'MLB999888'], 201),
        ]);

        $response = $this->actingAs($admin)->put("/admin/produtos/{$product->id}/canais/mercado_livre", [
            'is_enabled' => true,
            'attributes' => [],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $listing = ProductChannelListing::query()->where('product_id', $product->id)->first();
        $this->assertSame('MLB1652', $listing->attributes['category_id']);
        $this->assertSame(ProductChannelListing::STATUS_PUBLISHED, $listing->status);
        $this->assertSame('MLB999888', $listing->external_id);
    }

    public function test_a_manually_provided_category_id_is_not_overridden(): void
    {
        $this->connectMercadoLivre();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['name' => 'Notebook Dell Inspiron 15']);

        Http::fake([
            'https://api.mercadolibre.com/items' => Http::response(['id' => 'MLB111222'], 201),
        ]);

        $response = $this->actingAs($admin)->put("/admin/produtos/{$product->id}/canais/mercado_livre", [
            'is_enabled' => true,
            'attributes' => ['category_id' => 'MLB9999'],
        ]);

        $response->assertRedirect();

        $listing = ProductChannelListing::query()->where('product_id', $product->id)->first();
        $this->assertSame('MLB9999', $listing->attributes['category_id']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'domain_discovery'));
    }

    public function test_when_discovery_fails_the_channel_is_disabled_with_a_manual_instruction(): void
    {
        $this->connectMercadoLivre();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['name' => 'Produto sem categoria óbvia']);

        Http::fake([
            'https://api.mercadolibre.com/sites/MLB/domain_discovery/search*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($admin)->put("/admin/produtos/{$product->id}/canais/mercado_livre", [
            'is_enabled' => true,
            'attributes' => [],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');

        $listing = ProductChannelListing::query()->where('product_id', $product->id)->first();
        $this->assertFalse($listing->is_enabled);
        $this->assertSame(ProductChannelListing::STATUS_DRAFT, $listing->status);
    }
}

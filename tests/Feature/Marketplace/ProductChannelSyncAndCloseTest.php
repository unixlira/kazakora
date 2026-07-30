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

class ProductChannelSyncAndCloseTest extends TestCase
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

    private function publishedListing(Product $product): ProductChannelListing
    {
        return $product->channelListings()->create([
            'channel' => 'mercado_livre',
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => 'MLB4972163779',
            'attributes' => ['category_id' => 'MLB418306'],
        ]);
    }

    public function test_resaving_an_already_published_listing_updates_instead_of_creating_a_duplicate(): void
    {
        $this->connectMercadoLivre();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();
        $this->publishedListing($product);

        Http::fake([
            'https://api.mercadolibre.com/items/MLB4972163779' => Http::response(['id' => 'MLB4972163779'], 200),
        ]);

        $response = $this->actingAs($admin)->put("/admin/produtos/{$product->id}/canais/mercado_livre", [
            'is_enabled' => true,
            'attributes' => ['category_id' => 'MLB418306'],
        ]);

        $response->assertRedirect();

        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.mercadolibre.com/items' && $request->method() === 'POST');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'items/MLB4972163779') && $request->method() === 'PUT');
    }

    public function test_sync_updates_price_and_stock_on_the_platform(): void
    {
        $this->connectMercadoLivre();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['stock' => 42]);
        $this->publishedListing($product);

        Http::fake([
            'https://api.mercadolibre.com/items/MLB4972163779' => Http::response(['id' => 'MLB4972163779'], 200),
        ]);

        $response = $this->actingAs($admin)->post("/admin/produtos/{$product->id}/canais/mercado_livre/sincronizar");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'items/MLB4972163779')
            && $request->method() === 'PUT'
            && $request['available_quantity'] === 42);
    }

    public function test_sync_without_a_prior_publish_warns_instead_of_erroring(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();

        $response = $this->actingAs($admin)->post("/admin/produtos/{$product->id}/canais/mercado_livre/sincronizar");

        $response->assertRedirect();
        $response->assertSessionHas('warning');
    }

    public function test_destroy_closes_the_listing_on_the_platform(): void
    {
        $this->connectMercadoLivre();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();
        $listing = $this->publishedListing($product);

        Http::fake([
            'https://api.mercadolibre.com/items/MLB4972163779' => Http::response(['id' => 'MLB4972163779', 'status' => 'closed'], 200),
        ]);

        $response = $this->actingAs($admin)->delete("/admin/produtos/{$product->id}/canais/mercado_livre");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'items/MLB4972163779')
            && $request->method() === 'PUT'
            && $request['status'] === 'closed');

        $listing->refresh();
        $this->assertFalse($listing->is_enabled);
    }

    public function test_destroy_without_a_prior_publish_warns_instead_of_erroring(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();

        $response = $this->actingAs($admin)->delete("/admin/produtos/{$product->id}/canais/mercado_livre");

        $response->assertRedirect();
        $response->assertSessionHas('warning');
    }
}

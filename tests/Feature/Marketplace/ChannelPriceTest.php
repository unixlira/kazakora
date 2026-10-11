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

/**
 * Cada canal tem seu preço (pedido do usuário 2026-10-07): o preço do site
 * (R$ 79,04 no bebedouro #32) ia pro Mercado Livre, onde comissão Premium +
 * frete grátis exigem R$ 160,48 pra ter 30% de margem.
 */
class ChannelPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();

        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(), 'ml_user_id' => 123456789, 'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token', 'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6), 'scopes' => ['offline_access', 'read', 'write'],
        ]);
        MarketplaceAccount::query()->create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456789', 'connected_at' => now(),
        ]);

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        // Preço da loja com o +5% do Pix (pedido 2026-10-09): 79,04 × 1,05 = 82,99.
        // Sem preço próprio, o canal recebe o preço SEM o acréscimo (79,04).
        $this->produto = Product::factory()->create(['price' => 82.99, 'discount_percentage' => 0, 'discount_amount' => 0, 'stock' => 3]);
        $this->produto->channelListings()->create([
            'channel' => 'mercado_livre', 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => 'MLB7229164246', 'attributes' => ['category_id' => 'MLB178920'],
        ]);
    }

    public function test_saving_the_channel_price_does_not_touch_the_marketplace(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->put("/admin/produtos/{$this->produto->id}/canais/mercado_livre/preco", ['price' => 160.48])
            ->assertRedirect()->assertSessionHas('success');

        Http::assertNothingSent();
        $this->assertSame('160.48', $this->produto->channelListings()->first()->price);
    }

    public function test_sync_sends_the_channel_price_not_the_product_price(): void
    {
        $this->produto->channelListings()->update(['price' => 160.48]);
        Http::fake(['https://api.mercadolibre.com/items/MLB7229164246' => Http::response(['id' => 'MLB7229164246'])]);

        $this->actingAs($this->admin)->post("/admin/produtos/{$this->produto->id}/canais/mercado_livre/sincronizar")->assertRedirect();

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['price'] == 160.48 && $request['available_quantity'] === 3);
    }

    public function test_without_a_channel_price_the_product_price_without_pix_markup_is_used(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/MLB7229164246' => Http::response(['id' => 'MLB7229164246'])]);

        $this->actingAs($this->admin)->post("/admin/produtos/{$this->produto->id}/canais/mercado_livre/sincronizar")->assertRedirect();

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['price'] == 79.04);
    }

    public function test_clearing_the_channel_price_falls_back_to_the_product_price_without_pix_markup(): void
    {
        $this->produto->channelListings()->update(['price' => 160.48]);

        $this->actingAs($this->admin)->put("/admin/produtos/{$this->produto->id}/canais/mercado_livre/preco", ['price' => null])->assertRedirect();

        $listing = $this->produto->channelListings()->first();
        $this->assertNull($listing->price);
        $this->assertSame(79.04, $listing->precoDeVenda($this->produto->fresh()));
    }
}

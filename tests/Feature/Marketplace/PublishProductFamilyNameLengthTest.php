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
 * BUG REAL 2026-08-19 (importação da "Bike Spinning" da Shopee pro
 * Mercado Livre, categoria MLB3095 "Bicicletas Ergométricas"): quando uma
 * categoria exige `family_name` (ver requiresProductFamily()), o retry
 * usava `$product->name` cru — o nome real do anúncio da Shopee (copiado
 * ao importar) tinha 119 caracteres, e a ML rejeita family_name acima de
 * 60 com 400 "item.family_name.length_invalid", derrubando a publicação
 * inteira mesmo depois do retry. `MercadoLivreDriver::publishProduct()`
 * agora corta pro limite real da ML (`Str::limit(..., 60, '')`).
 */
class PublishProductFamilyNameLengthTest extends TestCase
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

    public function test_long_product_name_is_truncated_to_60_chars_on_family_name_retry(): void
    {
        $this->connectMercadoLivre();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Nome real do anúncio importado da Shopee — 119 caracteres.
        $longName = 'Bicicleta Ergométrica, Bike Spinning, Bicicleta Profissional de Academia, 120 kg, Cor Preto E Vermelho - High Quality';
        $this->assertGreaterThan(60, mb_strlen($longName));

        $product = Product::factory()->create(['name' => $longName]);

        Http::fake([
            'https://api.mercadolibre.com/items' => Http::sequence()
                // 1ª tentativa: categoria exige family_name.
                ->push([
                    'cause' => [['message' => 'Missing required parameter: family_name']],
                ], 400)
                // 2ª tentativa (retry com family_name): sucesso.
                ->push(['id' => 'MLB123456789'], 201),
        ]);

        $response = $this->actingAs($admin)->put("/admin/produtos/{$product->id}/canais/mercado_livre", [
            'is_enabled' => true,
            'attributes' => ['category_id' => 'MLB3095'],
        ]);

        $response->assertRedirect();

        $listing = ProductChannelListing::query()->where('product_id', $product->id)->first();
        $this->assertSame(ProductChannelListing::STATUS_PUBLISHED, $listing->status);
        $this->assertSame('MLB123456789', $listing->external_id);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.mercadolibre.com/items' || ! isset($request['family_name'])) {
                return false;
            }

            $this->assertLessThanOrEqual(60, mb_strlen($request['family_name']));
            $this->assertArrayNotHasKey('title', $request->data());

            return true;
        });
    }
}

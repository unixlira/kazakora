<?php

namespace Tests\Feature\Catalog;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 2026-08-06: apps "Seller In House" da Shopee autorizam
 * direto pelo console deles (botão "Authorize"), usando a "Redirect URL
 * Domain" cadastrada lá — que é só o domínio raiz (sem /api/shopee/
 * callback). code/shop_id chegam na home (CatalogController::index) em
 * vez do endpoint dedicado. Ver CatalogController::
 * handleShopeeAuthorizationLandingOnHome().
 */
class HomeShopeeAuthorizationLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_processes_shopee_code_and_shop_id_when_present_in_the_querystring(): void
    {
        Http::fake([
            'https://partner.test-stable.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'access_token' => 'shopee-access-token',
                'refresh_token' => 'shopee-refresh-token',
                'expire_in' => 14400,
            ]),
        ]);

        $response = $this->get('/?code=abc123&shop_id=564186623');

        $response->assertRedirect('/admin/integracoes');
        $response->assertSessionHas('success');

        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        $this->assertNotNull($account);
        $this->assertTrue($account->isConnected());
        $this->assertSame('564186623', $account->seller_id);
    }

    public function test_home_shows_the_real_error_when_shopee_rejects_the_code(): void
    {
        Http::fake([
            'https://partner.test-stable.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'error' => 'invalid_code',
                'message' => 'The code is expired or used or invalid, please check the code.',
            ], 403),
        ]);

        $response = $this->get('/?code=abc123&shop_id=564186623');

        $response->assertRedirect('/admin/integracoes');
        $response->assertSessionHas('error');
        $this->assertSame(0, MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->count());
    }

    public function test_home_renders_the_normal_catalog_page_when_no_shopee_params_are_present(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }
}

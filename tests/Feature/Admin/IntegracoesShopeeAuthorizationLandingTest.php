<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 2026-08-07: a suposição da correção anterior (ver
 * HomeShopeeAuthorizationLandingTest) de que a "Redirect URL Domain" da
 * Shopee seria o domínio raiz "/" estava errada — o usuário confirmou que
 * configurou ela como "/admin/integracoes" mesmo. code/shop_id chegavam
 * nesse endpoint (IntegrationController::index) e eram silenciosamente
 * ignorados: nenhum erro aparecia, a página só renderizava normal, e a
 * loja nunca ficava conectada. Ver IntegrationController::
 * handleShopeeAuthorizationLanding().
 */
class IntegracoesShopeeAuthorizationLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_integracoes_processes_shopee_code_and_shop_id_when_present_in_the_querystring(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Http::fake([
            'https://partner.test-stable.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'access_token' => 'shopee-access-token',
                'refresh_token' => 'shopee-refresh-token',
                'expire_in' => 14400,
            ]),
        ]);

        $response = $this->actingAs($admin)->get('/admin/integracoes?code=abc123&shop_id=564186623');

        $response->assertRedirect('/admin/integracoes');
        $response->assertSessionHas('success');

        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        $this->assertNotNull($account);
        $this->assertTrue($account->isConnected());
        $this->assertSame('564186623', $account->seller_id);
    }

    public function test_integracoes_shows_the_real_error_when_shopee_rejects_the_code(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Http::fake([
            'https://partner.test-stable.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'error' => 'invalid_code',
                'message' => 'The code is expired or used or invalid, please check the code.',
            ], 403),
        ]);

        $response = $this->actingAs($admin)->get('/admin/integracoes?code=abc123&shop_id=564186623');

        $response->assertRedirect('/admin/integracoes');
        $response->assertSessionHas('error');
        $this->assertSame(0, MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->count());
    }

    public function test_integracoes_renders_normally_when_no_shopee_params_are_present(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/admin/integracoes');

        $response->assertOk();
    }
}

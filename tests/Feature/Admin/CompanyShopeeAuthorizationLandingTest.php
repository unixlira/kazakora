<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 2026-08-07: /admin/empresa é o terceiro destino observado do
 * redirect de autorização "Seller In House" da Shopee (depois da raiz "/" e
 * de /admin/integracoes) — ver HandlesShopeeAuthorizationLanding. Mesmo
 * comportamento nos 3 pontos de entrada, cobertura espelhada de propósito.
 */
class CompanyShopeeAuthorizationLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_empresa_processes_shopee_code_and_shop_id_when_present_in_the_querystring(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Http::fake([
            'https://partner.test-stable.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'access_token' => 'shopee-access-token',
                'refresh_token' => 'shopee-refresh-token',
                'expire_in' => 14400,
            ]),
        ]);

        $response = $this->actingAs($admin)->get('/admin/empresa?code=abc123&shop_id=564186623');

        $response->assertRedirect('/admin/integracoes');
        $response->assertSessionHas('success');

        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        $this->assertNotNull($account);
        $this->assertTrue($account->isConnected());
        $this->assertSame('564186623', $account->seller_id);
    }

    public function test_empresa_renders_normally_when_no_shopee_params_are_present(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/admin/empresa');

        $response->assertOk();
    }
}

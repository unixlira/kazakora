<?php

namespace Tests\Feature\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bug real encontrado 2026-08-06 (suporte da Shopee apontou "endpoint
     * errado" ao autorizar): esse link usa um host regional próprio
     * (auth_base_url, Brasil), NÃO o api_base_url das chamadas de API, e
     * não é assinado com HMAC — só o formato simples documentado em
     * open.shopee.com/developer-guide/20. Ver ShopeeAuthServiceTest para
     * a cobertura unitária mais detalhada; aqui só confirma que a rota
     * real (/api/shopee/auth) devolve esse link.
     */
    public function test_redirect_to_auth_generates_the_regional_unsigned_authorization_url(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/api/shopee/auth');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://open.test-stable.shopee.com/auth?', $location);
        $this->assertStringNotContainsString('sign=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('1239829', $query['partner_id']);
        $this->assertSame('seller', $query['auth_type']);
        $this->assertSame('code', $query['response_type']);
    }

    public function test_callback_signs_the_token_exchange_request_correctly(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1735689600));
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Http::fake([
            'https://partner.test-stable.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'access_token' => 'shopee-access-token',
                'refresh_token' => 'shopee-refresh-token',
                'expire_in' => 14400,
            ]),
        ]);

        $this->actingAs($admin)->get('/api/shopee/callback?code=abc123&shop_id=564186623');

        $expectedSign = hash_hmac(
            'sha256',
            '1239829/api/v2/auth/token/get1735689600',
            'test-partner-key'
        );

        Http::assertSent(function ($request) use ($expectedSign) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/api/v2/auth/token/get')
                && ($query['sign'] ?? null) === $expectedSign
                && ($query['partner_id'] ?? null) === '1239829';
        });
    }

    public function test_callback_exchanges_code_for_shop_token_and_persists_marketplace_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Http::fake([
            'https://partner.test-stable.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'access_token' => 'shopee-access-token',
                'refresh_token' => 'shopee-refresh-token',
                'expire_in' => 14400,
            ]),
        ]);

        $response = $this->actingAs($admin)->get('/api/shopee/callback?code=abc123&shop_id=564186623');

        $response->assertRedirect('/admin/empresa');

        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        $this->assertNotNull($account);
        $this->assertTrue($account->isConnected());
        $this->assertSame('564186623', $account->seller_id);
        $this->assertSame('shopee-access-token', $account->access_token);
    }

    public function test_callback_with_shopee_error_response_does_not_persist_and_shows_error(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Http::fake([
            'https://partner.test-stable.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'error' => 'error_sign',
                'message' => 'Wrong sign.',
            ], 403),
        ]);

        $response = $this->actingAs($admin)->get('/api/shopee/callback?code=abc123&shop_id=564186623');

        $response->assertRedirect('/admin/empresa');
        $response->assertSessionHas('error');
        $this->assertSame(0, MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->count());
    }
}

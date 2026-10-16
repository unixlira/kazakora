<?php

namespace Tests\Feature\Amazon;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Amazon\AmazonAuthService;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Login with Amazon (LWA): link de autorização, callback OAuth, conexão
 * manual por refresh token, renovação do token, desconexão — pelo serviço,
 * pelo AmazonController (/api/amazon/*) e pelo IntegrationController.
 */
class AmazonAuthTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellingpartnerapi.test';

    private const LWA = 'https://api.amazon.com/auth/o2/token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amazon.sp_api_base_url' => self::BASE,
            'services.amazon.app_id' => 'amzn1.sp.solution.app',
            'services.amazon.lwa_client_id' => 'cid',
            'services.amazon.lwa_client_secret' => 'csecret',
            'services.amazon.auth_base_url' => 'https://sellercentral.amazon.com.br/',
            'services.amazon.auth_draft_mode' => false,
            'services.amazon.marketplace_id' => 'A2Q3Y263D00KWC',
            'services.amazon.bootstrap_refresh_token' => null,
            'services.amazon.bootstrap_seller_id' => null,
        ]);
    }

    private function auth(): AmazonAuthService
    {
        return app(AmazonAuthService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_authorization_url_has_only_application_id_and_state(): void
    {
        $url = $this->auth()->getAuthorizationUrl('abc');

        $this->assertSame('https://sellercentral.amazon.com.br/apps/authorize/consent?application_id=amzn1.sp.solution.app&state=abc', $url);
    }

    public function test_authorization_url_in_draft_mode_adds_version_beta(): void
    {
        config(['services.amazon.auth_draft_mode' => true]);

        $this->assertStringEndsWith('&version=beta', $this->auth()->getAuthorizationUrl('abc'));
    }

    public function test_callback_code_is_exchanged_and_the_account_is_saved(): void
    {
        Http::fake([self::LWA => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600], 200)]);

        $conta = $this->auth()->handleCallback('code-1', 'SELLER1');

        $this->assertTrue($conta->isConnected());
        $this->assertSame('SELLER1', $conta->seller_id);
        $this->assertSame('rt', $conta->refresh_token);
        $this->assertSame('A2Q3Y263D00KWC', $conta->metadata['marketplace_id']);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'code-1' && $r['client_id'] === 'cid');
    }

    public function test_callback_rejected_by_lwa_raises_with_status(): void
    {
        Http::fake([self::LWA => Http::response(['error' => 'invalid_grant', 'error_description' => 'code expirado'], 400)]);

        try {
            $this->auth()->handleCallback('velho', null);
            $this->fail('Deveria lançar');
        } catch (AmazonException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('code expirado', $e->getMessage());
        }

        $this->assertSame(0, MarketplaceAccount::count());
    }

    public function test_manual_connect_validates_against_sellers_api_and_keeps_the_seller_id(): void
    {
        Http::fake([
            self::LWA => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            self::BASE.'/sellers/v1/marketplaceParticipations' => Http::response(['payload' => [['participation' => ['sellerId' => 'REAL1']]]], 200),
        ]);

        $conta = $this->auth()->connectWithRefreshToken('rt-colado');

        $this->assertTrue($conta->isConnected());
        $this->assertSame('REAL1', $conta->seller_id);
        $this->assertSame('rt-colado', $conta->refresh_token);
    }

    public function test_manual_connect_with_token_without_permission_leaves_the_account_in_error(): void
    {
        Http::fake([
            self::LWA => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            self::BASE.'/sellers/v1/marketplaceParticipations' => Http::response(['errors' => [['message' => 'Unauthorized']]], 403),
        ]);

        try {
            $this->auth()->connectWithRefreshToken('rt-ruim');
            $this->fail('Deveria lançar');
        } catch (AmazonException $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->assertSame(MarketplaceAccount::STATUS_ERROR, MarketplaceAccount::first()->status);
    }

    public function test_manual_connect_with_invalid_refresh_token_saves_nothing(): void
    {
        Http::fake([self::LWA => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(AmazonException::class);

        try {
            $this->auth()->connectWithRefreshToken('rt-invalido');
        } finally {
            $this->assertSame(0, MarketplaceAccount::count());
        }
    }

    public function test_refresh_keeps_the_old_refresh_token_when_lwa_does_not_send_a_new_one(): void
    {
        $conta = MarketplaceAccount::create(['channel' => 'amazon', 'status' => 'connected', 'seller_id' => 'S1', 'access_token' => 'velho', 'refresh_token' => 'rt', 'token_expires_at' => now(), 'metadata' => ['marketplace_id' => 'X']]);
        Http::fake([self::LWA => Http::response(['access_token' => 'novo', 'expires_in' => 3600], 200)]);

        $renovada = $this->auth()->refreshToken($conta);

        $this->assertSame('novo', $renovada->access_token);
        $this->assertSame('rt', $renovada->refresh_token);
        $this->assertSame('S1', $renovada->seller_id);
        $this->assertSame(['marketplace_id' => 'X'], $renovada->metadata);
        $this->assertTrue($renovada->token_expires_at->gt(now()->addMinutes(59)));
    }

    public function test_refresh_failure_raises_with_status(): void
    {
        $conta = MarketplaceAccount::create(['channel' => 'amazon', 'status' => 'connected', 'access_token' => 'v', 'refresh_token' => 'rt', 'token_expires_at' => now()]);
        Http::fake([self::LWA => Http::response(['error' => 'invalid_client', 'error_description' => 'cliente inválido'], 401)]);

        $this->expectException(AmazonException::class);
        $this->expectExceptionCode(401);

        $this->auth()->refreshToken($conta);
    }

    public function test_ensure_valid_token_only_renews_close_to_expiry(): void
    {
        $conta = MarketplaceAccount::create(['channel' => 'amazon', 'status' => 'connected', 'access_token' => 'ok', 'refresh_token' => 'rt', 'token_expires_at' => now()->addMinutes(30)]);
        Http::fake([self::LWA => Http::response(['access_token' => 'novo', 'expires_in' => 3600], 200)]);

        $this->assertSame('ok', $this->auth()->ensureValidToken($conta)->access_token);
        Http::assertNothingSent();

        $conta->update(['token_expires_at' => now()->addMinutes(4)]);
        $this->assertSame('novo', $this->auth()->ensureValidToken($conta->fresh())->access_token);
    }

    public function test_ensure_valid_token_refuses_disconnected_account(): void
    {
        $conta = MarketplaceAccount::create(['channel' => 'amazon', 'status' => 'disconnected', 'access_token' => null]);

        $this->expectException(AmazonException::class);

        $this->auth()->ensureValidToken($conta);
    }

    public function test_auth_route_redirects_to_amazon_with_the_state_in_session(): void
    {
        $resposta = $this->actingAs($this->admin())->get('/api/amazon/auth');

        $resposta->assertRedirect();
        $state = session('amazon_oauth_state');
        $this->assertSame(40, strlen($state));
        $this->assertStringContainsString("state={$state}", $resposta->headers->get('Location'));
    }

    public function test_auth_routes_are_admin_only(): void
    {
        $anonimo = $this->get('/api/amazon/auth');
        $this->assertContains($anonimo->status(), [401, 302]);
        $this->assertStringNotContainsString('sellercentral', (string) $anonimo->headers->get('Location'));
        $this->assertNull(session('amazon_oauth_state'));

        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($cliente)->get('/api/amazon/auth')->assertStatus(403);
    }

    public function test_callback_with_wrong_state_does_not_call_amazon(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->withSession(['amazon_oauth_state' => 'certo'])
            ->get('/api/amazon/callback?spapi_oauth_code=c&state=errado')
            ->assertRedirect('/admin/integracoes')
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_callback_with_right_state_connects(): void
    {
        Http::fake([self::LWA => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600], 200)]);

        $this->actingAs($this->admin())
            ->withSession(['amazon_oauth_state' => 'certo'])
            ->get('/api/amazon/callback?spapi_oauth_code=c&state=certo&selling_partner_id=S9')
            ->assertRedirect('/admin/integracoes')
            ->assertSessionHas('success');

        $this->assertSame('S9', MarketplaceAccount::first()->seller_id);
    }

    public function test_callback_error_from_lwa_goes_back_with_the_message(): void
    {
        Http::fake([self::LWA => Http::response(['error_description' => 'código usado'], 400)]);

        $this->actingAs($this->admin())
            ->withSession(['amazon_oauth_state' => 'certo'])
            ->get('/api/amazon/callback?spapi_oauth_code=c&state=certo')
            ->assertSessionHas('error', 'código usado');
    }

    public function test_admin_manual_connect_and_disconnect(): void
    {
        Http::fake([
            self::LWA => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            self::BASE.'/sellers/v1/marketplaceParticipations' => Http::response(['payload' => [['participation' => ['sellerId' => 'S1']]]], 200),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/integracoes/amazon/conectar', ['refresh_token' => 'rt'])->assertSessionHas('success');
        $this->assertTrue(MarketplaceAccount::first()->isConnected());

        $this->actingAs($admin)->delete('/admin/integracoes/amazon')->assertSessionHas('success', 'Amazon desconectada.');
        $conta = MarketplaceAccount::first();
        $this->assertSame(MarketplaceAccount::STATUS_DISCONNECTED, $conta->status);
        $this->assertNull($conta->refresh_token);
    }

    public function test_admin_manual_connect_without_token_asks_for_one(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->post('/admin/integracoes/amazon/conectar', [])->assertSessionHas('error');

        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_to_auth_generates_a_pkce_authorization_url(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/api/mercadolivre/auth');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://auth.mercadolivre.com.br/authorization?', $location);
        $this->assertStringContainsString('client_id=test-app-id', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_callback_exchanges_code_for_token_and_persists_it(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $state = $this->extractState(
            $this->actingAs($admin)->get('/api/mercadolivre/auth')->headers->get('Location')
        );

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'APP_USR-access-token',
                'token_type' => 'bearer',
                'expires_in' => 21600,
                'scope' => 'offline_access read write',
                'user_id' => 123456789,
                'refresh_token' => 'TG-refresh-token',
            ]),
            'https://api.mercadolibre.com/users/me' => Http::response([
                'id' => 123456789,
                'nickname' => 'LOJA_KAZAKORA',
            ]),
        ]);

        $response = $this->actingAs($admin)->get("/api/mercadolivre/callback?code=abc123&state={$state}");

        $response->assertRedirect('/admin/empresa');

        $token = MercadoLivreToken::query()->where('ml_user_id', 123456789)->first();
        $this->assertNotNull($token);
        $this->assertSame('LOJA_KAZAKORA', $token->ml_nickname);
        $this->assertSame('APP_USR-access-token', $token->access_token);
        $this->assertTrue($token->isActive());
        $this->assertContains('offline_access', $token->scopes);

        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->first();
        $this->assertNotNull($account);
        $this->assertTrue($account->isConnected());
        $this->assertSame('123456789', $account->seller_id);
    }

    public function test_callback_with_invalid_state_shows_an_error_without_creating_a_token(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/api/mercadolivre/callback?code=abc123&state=nao-existe');

        $response->assertRedirect('/admin/empresa');
        $response->assertSessionHas('error');
        $this->assertSame(0, MercadoLivreToken::query()->count());
    }

    private function extractState(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    }
}

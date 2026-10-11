<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function seedExpiredToken(): MercadoLivreToken
    {
        return MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'token_expires_at' => now()->subMinute(),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);
    }

    public function test_ensure_valid_token_refreshes_an_expired_token(): void
    {
        $token = $this->seedExpiredToken();

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'new-access-token',
                'token_type' => 'bearer',
                'expires_in' => 21600,
                'scope' => 'offline_access read write',
                'user_id' => 123456789,
                'refresh_token' => 'new-refresh-token',
            ]),
        ]);

        $refreshed = app(MercadoLivreAuthService::class)->ensureValidToken($token);

        $this->assertSame('new-access-token', $refreshed->access_token);
        $this->assertSame('new-refresh-token', $refreshed->refresh_token);
        $this->assertTrue($refreshed->isActive());

        Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'old-refresh-token');

        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->first();
        $this->assertSame('new-access-token', $account->access_token);
    }

    public function test_ensure_valid_token_leaves_a_still_valid_token_untouched(): void
    {
        $token = MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 111,
            'access_token' => 'still-valid',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHours(3),
        ]);

        Http::fake();

        $result = app(MercadoLivreAuthService::class)->ensureValidToken($token);

        $this->assertSame('still-valid', $result->access_token);
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\MercadoLivre;

use App\Models\MercadoLivreToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefreshTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refreshes_tokens_expiring_within_the_hour(): void
    {
        $expiringSoon = MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 111,
            'access_token' => 'old-token',
            'refresh_token' => 'old-refresh',
            'token_expires_at' => now()->addMinutes(30),
        ]);

        $stillFresh = MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 222,
            'access_token' => 'fresh-token',
            'refresh_token' => 'fresh-refresh',
            'token_expires_at' => now()->addHours(5),
        ]);

        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'refreshed-token',
                'expires_in' => 21600,
                'scope' => 'offline_access read write',
                'user_id' => 111,
                'refresh_token' => 'refreshed-refresh',
            ]),
        ]);

        $this->artisan('mercadolivre:refresh-tokens')->assertExitCode(0);

        $this->assertSame('refreshed-token', $expiringSoon->fresh()->access_token);
        $this->assertSame('fresh-token', $stillFresh->fresh()->access_token);

        Http::assertSentCount(1);
    }

    public function test_command_is_a_no_op_when_nothing_is_expiring(): void
    {
        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 333,
            'access_token' => 'fresh-token',
            'refresh_token' => 'fresh-refresh',
            'token_expires_at' => now()->addHours(5),
        ]);

        Http::fake();

        $this->artisan('mercadolivre:refresh-tokens')->assertExitCode(0);

        Http::assertNothingSent();
    }
}

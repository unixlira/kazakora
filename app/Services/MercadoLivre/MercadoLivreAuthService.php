<?php

namespace App\Services\MercadoLivre;

use App\Models\MercadoLivreToken;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\DTOs\TokenDTO;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth2 (authorization code + PKCE) flow for the Mercado Livre API.
 *
 * @see https://developers.mercadolivre.com.br/pt_br/autenticacao-e-autorizacao
 */
class MercadoLivreAuthService
{
    private const STATE_CACHE_PREFIX = 'mercadolivre.oauth_state.';

    public function currentToken(): ?MercadoLivreToken
    {
        return MercadoLivreToken::current();
    }

    public function getAuthorizationUrl(): string
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(40);

        Cache::put(self::STATE_CACHE_PREFIX.$state, $verifier, now()->addMinutes(10));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.mercadolivre.app_id'),
            'redirect_uri' => config('services.mercadolivre.redirect_uri'),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return config('services.mercadolivre.auth_url').'?'.$query;
    }

    public function handleCallback(string $code, string $state): MercadoLivreToken
    {
        $verifier = Cache::pull(self::STATE_CACHE_PREFIX.$state);

        if (! $verifier) {
            throw new MercadoLivreException('O estado (state) do OAuth do Mercado Livre é inválido ou expirou. Tente conectar novamente.');
        }

        $response = Http::asForm()->post(config('services.mercadolivre.token_url'), [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.mercadolivre.app_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
            'code' => $code,
            'redirect_uri' => config('services.mercadolivre.redirect_uri'),
            'code_verifier' => $verifier,
        ]);

        if ($response->failed()) {
            Log::channel(config('mercadolivre.log_channel'))->error('mercadolivre.oauth_callback_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new MercadoLivreException(
                'Não foi possível concluir a autenticação com o Mercado Livre.',
                $response->status(),
                ['body' => $response->json()],
            );
        }

        $tokenDto = $this->tokenDtoFromResponse($response->json());

        $nickname = $this->fetchNickname($tokenDto->access_token);

        return $this->persistToken($tokenDto, $nickname);
    }

    public function refreshToken(MercadoLivreToken $token): MercadoLivreToken
    {
        $response = Http::asForm()->post(config('services.mercadolivre.token_url'), [
            'grant_type' => 'refresh_token',
            'client_id' => config('services.mercadolivre.app_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
            'refresh_token' => $token->refresh_token,
        ]);

        if ($response->failed()) {
            Log::channel(config('mercadolivre.log_channel'))->error('mercadolivre.token_refresh_failed', [
                'ml_user_id' => $token->ml_user_id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new MercadoLivreException(
                'Não foi possível renovar o token do Mercado Livre.',
                $response->status(),
                ['body' => $response->json()],
            );
        }

        $tokenDto = $this->tokenDtoFromResponse($response->json());

        return $this->persistToken($tokenDto, $token->ml_nickname);
    }

    public function ensureValidToken(?MercadoLivreToken $token): MercadoLivreToken
    {
        if (! $token) {
            throw new MercadoLivreException('Nenhuma conta do Mercado Livre está conectada. Conecte uma conta antes de continuar.');
        }

        return $token->isExpired() ? $this->refreshToken($token) : $token;
    }

    /**
     * Mercado Livre has no dedicated "revoke" endpoint for this flow, so
     * revoking means dropping the locally stored credentials and marking
     * the linked marketplace account as disconnected.
     */
    public function revokeToken(MercadoLivreToken $token): bool
    {
        MarketplaceAccount::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->where('seller_id', (string) $token->ml_user_id)
            ->update([
                'status' => MarketplaceAccount::STATUS_DISCONNECTED,
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
            ]);

        return (bool) $token->delete();
    }

    private function fetchNickname(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)
            ->baseUrl(config('services.mercadolivre.api_base_url'))
            ->get('users/me');

        return $response->successful() ? $response->json('nickname') : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tokenDtoFromResponse(array $payload): TokenDTO
    {
        return new TokenDTO(
            access_token: $payload['access_token'],
            refresh_token: $payload['refresh_token'],
            expires_in: (int) $payload['expires_in'],
            user_id: (int) $payload['user_id'],
            scopes: isset($payload['scope']) ? explode(' ', $payload['scope']) : [],
        );
    }

    private function persistToken(TokenDTO $tokenDto, ?string $nickname): MercadoLivreToken
    {
        $expiresAt = now()->addSeconds($tokenDto->expires_in);

        $token = MercadoLivreToken::query()->updateOrCreate(
            ['ml_user_id' => $tokenDto->user_id],
            [
                'ml_nickname' => $nickname,
                'access_token' => $tokenDto->access_token,
                'refresh_token' => $tokenDto->refresh_token,
                'token_expires_at' => $expiresAt,
                'scopes' => $tokenDto->scopes,
            ],
        );

        MarketplaceAccount::query()->updateOrCreate(
            ['channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE],
            [
                'status' => MarketplaceAccount::STATUS_CONNECTED,
                'seller_id' => (string) $tokenDto->user_id,
                'access_token' => $tokenDto->access_token,
                'refresh_token' => $tokenDto->refresh_token,
                'token_expires_at' => $expiresAt,
                'connected_at' => now(),
            ],
        );

        return $token;
    }
}

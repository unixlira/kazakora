<?php

namespace App\Services\MelhorEnvio;

use App\Models\MelhorEnvioToken;
use App\Services\MelhorEnvio\Exceptions\MelhorEnvioException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth2 (authorization code, sem PKCE — a Melhor Envio não exige) pra API
 * de frete. Mesmo formato do MercadoLivreAuthService (state em cache, token
 * único mais recente, refresh automático), sem a parte de PKCE/nickname que
 * é específica do Mercado Livre.
 *
 * @see https://docs.menv.io/reference/autentica%C3%A7%C3%A3o
 */
class MelhorEnvioAuthService
{
    private const STATE_CACHE_PREFIX = 'melhorenvio.oauth_state.';

    public function currentToken(): ?MelhorEnvioToken
    {
        return MelhorEnvioToken::current();
    }

    public function getAuthorizationUrl(): string
    {
        $state = Str::random(40);

        Cache::put(self::STATE_CACHE_PREFIX.$state, true, now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => config('services.melhorenvio.client_id'),
            'redirect_uri' => config('services.melhorenvio.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
            'scope' => 'shipping-calculate',
        ]);

        return config('services.melhorenvio.auth_url').'?'.$query;
    }

    public function handleCallback(string $code, string $state): MelhorEnvioToken
    {
        if (! Cache::pull(self::STATE_CACHE_PREFIX.$state)) {
            throw new MelhorEnvioException('O estado (state) do OAuth do Melhor Envio é inválido ou expirou. Tente conectar novamente.');
        }

        $response = Http::asForm()->post(config('services.melhorenvio.token_url'), [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.melhorenvio.client_id'),
            'client_secret' => config('services.melhorenvio.client_secret'),
            'redirect_uri' => config('services.melhorenvio.redirect_uri'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            Log::channel(config('melhorenvio.log_channel'))->error('melhorenvio.oauth_callback_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new MelhorEnvioException('Não foi possível concluir a autenticação com o Melhor Envio.', $response->status());
        }

        return $this->persistToken($response->json());
    }

    public function refreshToken(MelhorEnvioToken $token): MelhorEnvioToken
    {
        $response = Http::asForm()->post(config('services.melhorenvio.token_url'), [
            'grant_type' => 'refresh_token',
            'client_id' => config('services.melhorenvio.client_id'),
            'client_secret' => config('services.melhorenvio.client_secret'),
            'refresh_token' => $token->refresh_token,
        ]);

        if ($response->failed()) {
            Log::channel(config('melhorenvio.log_channel'))->error('melhorenvio.token_refresh_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new MelhorEnvioException('Não foi possível renovar o token do Melhor Envio.', $response->status());
        }

        return $this->persistToken($response->json(), $token->account_label);
    }

    public function ensureValidToken(?MelhorEnvioToken $token): MelhorEnvioToken
    {
        if (! $token) {
            throw new MelhorEnvioException('Nenhuma conta do Melhor Envio está conectada.');
        }

        return $token->isExpired() ? $this->refreshToken($token) : $token;
    }

    public function revokeToken(MelhorEnvioToken $token): bool
    {
        return (bool) $token->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistToken(array $payload, ?string $existingLabel = null): MelhorEnvioToken
    {
        $expiresAt = now()->addSeconds((int) $payload['expires_in']);
        $label = $existingLabel ?? $this->fetchAccountLabel($payload['access_token']);

        // Sem um identificador estável de conta (Melhor Envio não devolve um
        // "user_id" fixo no token como o Mercado Livre), mantemos uma única
        // conexão ativa por vez — updateOrCreate na linha mais recente.
        $existing = MelhorEnvioToken::current();

        $attributes = [
            'account_label' => $label,
            'access_token' => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'],
            'token_expires_at' => $expiresAt,
            'scopes' => isset($payload['scope']) ? explode(' ', $payload['scope']) : [],
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return MelhorEnvioToken::create([...$attributes, 'user_id' => Auth::id()]);
    }

    private function fetchAccountLabel(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)
            ->baseUrl(config('services.melhorenvio.api_base_url'))
            ->withHeaders(['User-Agent' => config('melhorenvio.user_agent')])
            ->get('me');

        if (! $response->successful()) {
            return null;
        }

        $firstname = $response->json('firstname');
        $lastname = $response->json('lastname');

        return trim("{$firstname} {$lastname}") ?: $response->json('email');
    }
}

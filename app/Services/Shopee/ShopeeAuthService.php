<?php

namespace App\Services\Shopee;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Shopee\Exceptions\ShopeeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth2 pra Shopee Open Platform v2 — autorização de loja (shop-level).
 *
 * Diferente do Mercado Livre (authorization code + PKCE), a Shopee assina
 * cada chamada (inclusive as de auth) com HMAC-SHA256 usando o partner_key,
 * não usa code_verifier nem "state" (sem proteção CSRF própria no fluxo —
 * decisão da própria Shopee, não uma omissão daqui).
 *
 * @see PDF oficial "OpenAPI Authorization & Authentication v1 & v2" (Shopee, 2021)
 */
class ShopeeAuthService
{
    public function getAuthorizationUrl(): string
    {
        $path = '/api/v2/shop/auth_partner';
        $timestamp = now()->timestamp;

        $query = http_build_query([
            'partner_id' => (int) config('services.shopee.partner_id'),
            'redirect' => config('services.shopee.redirect_url'),
            'timestamp' => $timestamp,
            'sign' => $this->publicSign($path, $timestamp),
        ]);

        return config('services.shopee.api_base_url').$path.'?'.$query;
    }

    public function handleCallback(string $code, int $shopId): MarketplaceAccount
    {
        $path = '/api/v2/auth/token/get';
        $timestamp = now()->timestamp;

        $response = Http::baseUrl(config('services.shopee.api_base_url'))
            ->post($path.'?'.http_build_query([
                'partner_id' => (int) config('services.shopee.partner_id'),
                'timestamp' => $timestamp,
                'sign' => $this->publicSign($path, $timestamp),
            ]), [
                'code' => $code,
                'shop_id' => $shopId,
                'partner_id' => (int) config('services.shopee.partner_id'),
            ]);

        if ($response->failed() || $response->json('error')) {
            Log::channel('shopee')->error('shopee.oauth_callback_failed', ['status' => $response->status(), 'body' => $response->json()]);

            throw new ShopeeException($response->json('message') ?? 'Não foi possível concluir a autenticação com a Shopee.', $response->status(), ['body' => $response->json()]);
        }

        return $this->persistToken($shopId, $response->json());
    }

    public function refreshToken(MarketplaceAccount $account): MarketplaceAccount
    {
        $path = '/api/v2/auth/access_token/get';
        $timestamp = now()->timestamp;
        $shopId = (int) $account->seller_id;

        $response = Http::baseUrl(config('services.shopee.api_base_url'))
            ->post($path.'?'.http_build_query([
                'partner_id' => (int) config('services.shopee.partner_id'),
                'timestamp' => $timestamp,
                'sign' => $this->publicSign($path, $timestamp),
            ]), [
                'refresh_token' => $account->refresh_token,
                'shop_id' => $shopId,
                'partner_id' => (int) config('services.shopee.partner_id'),
            ]);

        if ($response->failed() || $response->json('error')) {
            Log::channel('shopee')->error('shopee.token_refresh_failed', ['shop_id' => $shopId, 'status' => $response->status(), 'body' => $response->json()]);

            throw new ShopeeException($response->json('message') ?? 'Não foi possível renovar o token da Shopee.', $response->status(), ['body' => $response->json()]);
        }

        return $this->persistToken($shopId, $response->json());
    }

    public function ensureValidToken(MarketplaceAccount $account): MarketplaceAccount
    {
        if (! $account->isConnected() || ! $account->access_token) {
            throw new ShopeeException('Nenhuma loja da Shopee está conectada. Conecte uma conta antes de continuar.');
        }

        // Access token dura 4h (curto, ao contrário do ML) — renova com
        // folga de 5 min pra nunca usar um token vencido no meio de uma
        // chamada.
        if ($account->token_expires_at?->lte(now()->addMinutes(5))) {
            return $this->refreshToken($account);
        }

        return $account;
    }

    /**
     * Assinatura das chamadas "Public API" (sem access_token/shop_id na
     * string base) — usada só pra auth_partner/token/get/access_token/get.
     */
    private function publicSign(string $path, int $timestamp): string
    {
        $partnerId = (int) config('services.shopee.partner_id');
        $baseString = "{$partnerId}{$path}{$timestamp}";

        return hash_hmac('sha256', $baseString, (string) config('services.shopee.partner_key'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistToken(int $shopId, array $payload): MarketplaceAccount
    {
        $expiresAt = now()->addSeconds((int) $payload['expire_in']);

        return MarketplaceAccount::query()->updateOrCreate(
            ['channel' => MarketplaceAccount::CHANNEL_SHOPEE],
            [
                'status' => MarketplaceAccount::STATUS_CONNECTED,
                'seller_id' => (string) $shopId,
                'access_token' => $payload['access_token'],
                'refresh_token' => $payload['refresh_token'],
                'token_expires_at' => $expiresAt,
                'connected_at' => now(),
            ],
        );
    }
}

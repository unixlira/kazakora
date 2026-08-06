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
 * as chamadas de API de verdade (token/get, access_token/get) com
 * HMAC-SHA256 usando o partner_key — mas o link de autorização em si
 * (getAuthorizationUrl) NÃO é assinado, usa um host regional fixo e um
 * formato de query simples (ver comentário do método). Sem code_verifier
 * nem "state" no callback (sem proteção CSRF própria nesse fluxo — decisão
 * da própria Shopee, não uma omissão daqui).
 *
 * @see https://open.shopee.com/developer-guide/20 (Authorization & Authentication v2, doc atual)
 */
class ShopeeAuthService
{
    /**
     * Achado real 2026-08-06 (suporte da Shopee apontou "endpoint errado"
     * ao tentar autorizar em produção/homolog): esse link NÃO usa o mesmo
     * host das chamadas de API (api_base_url) nem é assinado com HMAC —
     * ele usa um host REGIONAL fixo (auth_base_url, Brasil-específico) e
     * um formato de query simples (partner_id/auth_type/redirect_uri/
     * response_type), documentado em open.shopee.com/developer-guide/20
     * ("Generating the Authorization Link"). A versão antiga daqui
     * montava `{api_base_url}/api/v2/shop/auth_partner` com timestamp+sign
     * — isso batia com um PDF mais antigo da Shopee (2021, ainda citado na
     * classe), mas não é mais o link real que a Shopee aceita hoje pra
     * essa etapa. O restante do fluxo (handleCallback/refreshToken, que
     * SÃO chamadas de API de verdade contra api_base_url) continua
     * assinado normalmente — só o link de redirecionamento mudou.
     */
    public function getAuthorizationUrl(): string
    {
        $query = http_build_query([
            'partner_id' => (int) config('services.shopee.partner_id'),
            'auth_type' => 'seller',
            'redirect_uri' => config('services.shopee.redirect_url'),
            'response_type' => 'code',
        ]);

        return rtrim((string) config('services.shopee.auth_base_url'), '/').'/auth?'.$query;
    }

    public function handleCallback(string $code, int $shopId): MarketplaceAccount
    {
        $path = '/api/v2/auth/token/get';
        $timestamp = now()->timestamp;
        $sign = $this->publicSign($path, $timestamp);

        $this->logSigningAttempt('shopee.token_exchange_signed', $path, $timestamp, $sign, [
            'shop_id' => $shopId,
            'code_fingerprint' => substr($code, 0, 4).'...('.strlen($code).' chars)',
        ]);

        $queryString = http_build_query([
            'partner_id' => (int) config('services.shopee.partner_id'),
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]);

        $response = Http::baseUrl(config('services.shopee.api_base_url'))
            ->post($path.'?'.$queryString, [
                'code' => $code,
                'shop_id' => $shopId,
                'partner_id' => (int) config('services.shopee.partner_id'),
            ]);

        if ($response->failed() || $response->json('error')) {
            Log::channel('shopee')->error('shopee.oauth_callback_failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'request' => [
                    'full_url' => rtrim((string) config('services.shopee.api_base_url'), '/').$path.'?'.$queryString,
                    'path' => $path,
                    'timestamp' => $timestamp,
                    'base_string' => (int) config('services.shopee.partner_id')."{$path}{$timestamp}",
                    'sign' => $sign,
                    'key_fingerprint' => $this->keyFingerprint(),
                ],
            ]);

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
     * Log de diagnóstico da assinatura — nunca loga a partner_key crua, só
     * um fingerprint (bastante pra confirmar que dois pontos de chamada
     * estão lendo a MESMA chave, sem expor o segredo). Gated por
     * SHOPEE_DEBUG_SIGNING pra poder ligar/desligar via .env sem redeploy
     * quando for necessário investigar um "Wrong sign" da Shopee de novo.
     *
     * @param  array<string, mixed>  $extra
     */
    private function logSigningAttempt(string $event, string $path, int $timestamp, string $sign, array $extra = []): void
    {
        if (! config('services.shopee.debug_signing')) {
            return;
        }

        $partnerId = (int) config('services.shopee.partner_id');

        Log::channel('shopee')->debug($event, array_merge([
            'partner_id' => $partnerId,
            'path' => $path,
            'timestamp' => $timestamp,
            'base_string' => "{$partnerId}{$path}{$timestamp}",
            'sign' => $sign,
            'key_fingerprint' => $this->keyFingerprint(),
            'api_base_url' => config('services.shopee.api_base_url'),
        ], $extra));
    }

    private function keyFingerprint(): string
    {
        $key = (string) config('services.shopee.partner_key');

        return strlen($key) > 8
            ? substr($key, 0, 4).'...'.substr($key, -4).' (len='.strlen($key).')'
            : '(too short to fingerprint, len='.strlen($key).')';
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

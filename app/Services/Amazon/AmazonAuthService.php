<?php

namespace App\Services\Amazon;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Login with Amazon (LWA) — autenticação da Selling Partner API (SP-API).
 *
 * Diferente do Mercado Livre (PKCE) e da Shopee (HMAC-SHA256 nas chamadas):
 * a SP-API usa OAuth2 padrão via LWA (api.amazon.com/auth/o2/token) e, desde
 * out/2023, NÃO exige mais assinatura AWS SigV4 pras chamadas normais — só o
 * Bearer/`x-amz-access-token` do LWA (ver AmazonClient). A única assinatura
 * "extra" que sobrevive é o Restricted Data Token (RDT, Tokens API), exigido
 * pra ler dado pessoal do comprador (nome/endereço/CPF) — ver
 * AmazonClient::restrictedGet().
 *
 * Dois jeitos de conectar uma loja, ambos suportados aqui:
 * 1. OAuth completo (getAuthorizationUrl/handleCallback) — necessário pra um
 *    app SP-API publicado, onde o vendedor autoriza clicando num link.
 * 2. Self-authorization (connectWithRefreshToken) — apps privados (não
 *    publicados na loja de apps) deixam o próprio vendedor gerar um refresh
 *    token direto no Seller Central (Partner Network > Develop Apps >
 *    Autorizar) e colar no admin. É o caminho real pra uma conta única como
 *    a da Kazakora, sem precisar publicar o app.
 *
 * @see https://developer-docs.amazon.com/sp-api/docs/connecting-to-the-selling-partner-api
 */
class AmazonAuthService
{
    private const TOKEN_URL = 'https://api.amazon.com/auth/o2/token';

    /**
     * Confirmado contra a doc oficial (developer-docs.amazon/sp-api/docs/
     * website-authorization-workflow, 2026-08-07): a URI de autorização leva
     * SÓ `application_id`+`state` (+`version=beta` enquanto o app estiver em
     * estado "Draft") — `redirect_uri` NÃO é parâmetro dessa URL, fica
     * registrado à parte no cadastro do app no Seller Central (diferente do
     * OAuth "clássico" do Mercado Livre, que exige redirect_uri na query).
     * Mandar esse parâmetro a mais aqui não é documentado e pode ser
     * rejeitado pela Amazon.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $query = http_build_query(array_filter([
            'application_id' => config('services.amazon.app_id'),
            'state' => $state,
            // Exigido pelo próprio Amazon enquanto o app estiver em estado
            // "Draft" no Seller Central (self-teste antes de publicar).
            'version' => config('services.amazon.auth_draft_mode') ? 'beta' : null,
        ]));

        return rtrim((string) config('services.amazon.auth_base_url'), '/').'/apps/authorize/consent?'.$query;
    }

    /**
     * Troca o `spapi_oauth_code` do callback por um refresh_token real.
     * `sellingPartnerId` vem junto no callback (query `selling_partner_id`),
     * identifica a conta de vendedor que autorizou.
     */
    public function handleCallback(string $code, ?string $sellingPartnerId): MarketplaceAccount
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.amazon.lwa_client_id'),
            'client_secret' => config('services.amazon.lwa_client_secret'),
        ]);

        if ($response->failed()) {
            Log::channel('amazon')->error('amazon.oauth_callback_failed', ['status' => $response->status(), 'body' => $response->json()]);

            throw new AmazonException($response->json('error_description') ?? 'Não foi possível concluir a autenticação com a Amazon.', $response->status(), ['body' => $response->json()]);
        }

        Log::channel('amazon')->info('amazon.oauth_callback_succeeded', ['selling_partner_id' => $sellingPartnerId]);

        return $this->persistToken($sellingPartnerId, $response->json());
    }

    /**
     * Self-authorization: valida o refresh token de verdade (chama
     * `sellers/v1/marketplaceParticipations`, o endpoint mais leve da
     * SP-API) antes de persistir — evita salvar um token colado errado e só
     * descobrir isso na primeira venda.
     */
    public function connectWithRefreshToken(string $refreshToken, ?string $sellerId = null): MarketplaceAccount
    {
        $tokenPayload = $this->exchangeRefreshToken($refreshToken);
        $account = $this->persistToken($sellerId, $tokenPayload, $refreshToken);

        try {
            $participations = Http::baseUrl(config('services.amazon.sp_api_base_url'))
                ->withHeaders(['x-amz-access-token' => $account->access_token])
                ->get('/sellers/v1/marketplaceParticipations');

            if ($participations->failed()) {
                throw new AmazonException($participations->json('errors.0.message') ?? 'Token da Amazon inválido ou sem permissão.', $participations->status(), ['body' => $participations->json()]);
            }

            $resolvedSellerId = $participations->json('payload.0.participation.sellerId') ?? $sellerId;

            if ($resolvedSellerId && $resolvedSellerId !== $sellerId) {
                $account->update(['seller_id' => $resolvedSellerId]);
            }

            Log::channel('amazon')->info('amazon.manual_connect_succeeded', ['seller_id' => $resolvedSellerId]);
        } catch (AmazonException $exception) {
            // Reverte a conexão: token colado não é válido de verdade,
            // melhor deixar "desconectado" do que uma conta com token
            // quebrado que parece conectada mas falha em toda chamada real.
            $account->update(['status' => MarketplaceAccount::STATUS_ERROR]);

            throw $exception;
        }

        return $account->fresh();
    }

    public function refreshToken(MarketplaceAccount $account): MarketplaceAccount
    {
        $payload = $this->exchangeRefreshToken($account->refresh_token);

        return $this->persistToken($account->seller_id, $payload, $account->refresh_token, $account->metadata);
    }

    public function ensureValidToken(MarketplaceAccount $account): MarketplaceAccount
    {
        if (! $account->isConnected() || ! $account->access_token) {
            throw new AmazonException('Nenhuma conta da Amazon está conectada. Conecte uma conta antes de continuar.');
        }

        // Access token LWA dura 1h — renova com folga de 5 min, mesmo padrão
        // já usado pro Mercado Livre/Shopee.
        if ($account->token_expires_at?->lte(now()->addMinutes(5))) {
            return $this->refreshToken($account);
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeRefreshToken(string $refreshToken): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => config('services.amazon.lwa_client_id'),
            'client_secret' => config('services.amazon.lwa_client_secret'),
        ]);

        if ($response->failed()) {
            Log::channel('amazon')->error('amazon.token_refresh_failed', ['status' => $response->status(), 'body' => $response->json()]);

            throw new AmazonException($response->json('error_description') ?? 'Não foi possível renovar o token da Amazon.', $response->status(), ['body' => $response->json()]);
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $metadata
     */
    private function persistToken(?string $sellerId, array $payload, ?string $fallbackRefreshToken = null, ?array $metadata = null): MarketplaceAccount
    {
        $expiresAt = now()->addSeconds((int) ($payload['expires_in'] ?? 3600));

        return MarketplaceAccount::query()->updateOrCreate(
            ['channel' => MarketplaceAccount::CHANNEL_AMAZON],
            [
                'status' => MarketplaceAccount::STATUS_CONNECTED,
                'seller_id' => $sellerId,
                'access_token' => $payload['access_token'],
                // A resposta de refresh_token grant nem sempre devolve um
                // refresh_token novo — LWA reusa o mesmo indefinidamente até
                // ser revogado. Mantém o atual quando a resposta não trouxer um.
                'refresh_token' => $payload['refresh_token'] ?? $fallbackRefreshToken,
                'token_expires_at' => $expiresAt,
                'connected_at' => now(),
                'metadata' => $metadata ?? [
                    'marketplace_id' => config('services.amazon.marketplace_id'),
                    'region' => config('services.amazon.region'),
                    'sandbox' => config('services.amazon.sandbox'),
                ],
            ],
        );
    }
}

<?php

namespace App\Services\Bling;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Bling\Exceptions\BlingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth2 (authorization_code) do Bling — único grant que o Bling suporta
 * (nada de client_credentials/password/implicit).
 *
 * Hosts CONFIRMADOS no exemplo cURL literal de developer.bling.com.br/
 * aplicativos (achado real 2026-08-31 — a 1ª versão deste arquivo copiava
 * o SDK comunitário em JS bling-erp-api-js, que usa www.bling.com.br pros
 * 3 endpoints de OAuth; a doc OFICIAL mostra token e revoke em
 * api.bling.com.br, só authorize fica em www.bling.com.br — host errado
 * quebraria a troca do code por token silenciosamente. ajuda.bling.com.br
 * fica atrás de um desafio Cloudflare que bloqueia scraping direto, mas o
 * texto de developer.bling.com.br (via leitor que renderiza JS) tem os
 * cURLs literais, mais confiável que o SDK de terceiro nesse detalhe):
 *   - authorize: GET  https://www.bling.com.br/Api/v3/oauth/authorize
 *   - token:     POST https://api.bling.com.br/Api/v3/oauth/token
 *   - revoke:    POST https://api.bling.com.br/oauth/revoke (SEM /Api/v3)
 * Headers do token/revoke por esse mesmo exemplo: Content-Type
 * application/x-www-form-urlencoded, Accept: 1.0 (literal, não é engano —
 * é a versão da API, não um content-type de resposta) e Authorization
 * Basic client_id:client_secret em base64.
 *
 * Por que Bling e não TikTok Shop direto: a API do TikTok Shop exige
 * aprovação de parceiro (Partner Center) que este projeto ainda não tem —
 * ver TikTokShopDriver. O Bling já resolve a autenticação com o TikTok Shop
 * do lado dele (o vendedor conecta o TikTok Shop DENTRO do painel do Bling,
 * uma vez só, fora deste app) — daqui em diante só precisamos de OAuth2 com
 * o Bling em si e consultar pedidos/vendas filtrando pela loja que o Bling
 * já sincroniza com o TikTok Shop.
 *
 * Só 1 conta Bling por instalação (ao contrário do Mercado Livre, que tem
 * uma tabela dedicada pra token pensando em múltiplas contas) — reaproveita
 * MarketplaceAccount (channel=bling) sozinho, mesmo padrão simples já usado
 * pela Amazon.
 */
class BlingAuthService
{
    private const STATE_CACHE_PREFIX = 'bling.oauth_state.';

    public function currentAccount(): ?MarketplaceAccount
    {
        return MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_BLING)->first();
    }

    public function getAuthorizationUrl(): string
    {
        $state = Str::random(40);

        Cache::put(self::STATE_CACHE_PREFIX.$state, true, now()->addMinutes(10));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.bling.client_id'),
            'state' => $state,
            'redirect_uri' => config('services.bling.redirect_uri'),
        ]);

        return rtrim(config('services.bling.oauth_base_url'), '/')."/oauth/authorize?{$query}";
    }

    public function handleCallback(string $code, string $state): MarketplaceAccount
    {
        if (! Cache::pull(self::STATE_CACHE_PREFIX.$state)) {
            throw new BlingException('O estado (state) do OAuth do Bling é inválido ou expirou. Tente conectar novamente.');
        }

        $payload = $this->requestToken(['grant_type' => 'authorization_code', 'code' => $code]);

        return $this->persistToken($payload);
    }

    public function refreshToken(MarketplaceAccount $account): MarketplaceAccount
    {
        if (! $account->refresh_token) {
            throw new BlingException('Não há refresh_token do Bling disponível — reconecte a conta.');
        }

        $payload = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        return $this->persistToken($payload);
    }

    public function ensureValidToken(?MarketplaceAccount $account): MarketplaceAccount
    {
        if (! $account?->isConnected()) {
            throw new BlingException('Nenhuma conta do Bling está conectada. Conecte uma conta antes de continuar.');
        }

        // Folga de 60s antes do vencimento real — mesma cautela do resto do
        // projeto (ver MercadoLivreToken::isExpired()), evita bater na API
        // com um token que expira no meio da própria chamada.
        if ($account->token_expires_at && $account->token_expires_at->subSeconds(60)->isPast()) {
            return $this->refreshToken($account);
        }

        return $account;
    }

    /**
     * Bling tem /oauth/revoke de verdade (ao contrário de Mercado Livre/
     * Shopee, ver comentário equivalente em MercadoLivreAuthService) — mas
     * revogar aqui é best-effort: mesmo se o Bling recusar (token já
     * expirado, por exemplo), a conta local é desconectada do mesmo jeito.
     */
    public function revoke(MarketplaceAccount $account): void
    {
        if ($account->access_token) {
            try {
                // SEM /Api/v3 — o exemplo oficial de revoke usa o host de
                // recursos (api.bling.com.br) na raiz, diferente do token
                // (que leva /Api/v3). Ver docblock da classe.
                Http::baseUrl($this->apiHost())
                    ->withHeaders([
                        'Authorization' => 'Basic '.$this->basicAuth(),
                        'Accept' => '1.0',
                    ])
                    ->asForm()
                    ->post('oauth/revoke', ['token' => $account->access_token]);
            } catch (\Throwable $exception) {
                Log::warning('bling.revoke_failed', ['message' => $exception->getMessage()]);
            }
        }

        $account->update([
            'status' => MarketplaceAccount::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);
    }

    /**
     * @param  array<string, string>  $body
     * @return array<string, mixed>
     */
    private function requestToken(array $body): array
    {
        $response = Http::baseUrl(rtrim(config('services.bling.api_base_url'), '/'))
            ->withHeaders([
                'Authorization' => 'Basic '.$this->basicAuth(),
                // Literal do exemplo oficial (developer.bling.com.br/aplicativos)
                // — não é um Content-Type de resposta, é a versão da API.
                'Accept' => '1.0',
            ])
            ->asForm()
            ->post('oauth/token', $body);

        if ($response->failed()) {
            Log::error('bling.oauth_token_failed', ['status' => $response->status(), 'body' => $response->json()]);

            throw new BlingException(
                'Não foi possível concluir a autenticação com o Bling.',
                $response->status(),
                ['body' => $response->json()],
            );
        }

        return $response->json();
    }

    private function basicAuth(): string
    {
        return base64_encode(config('services.bling.client_id').':'.config('services.bling.client_secret'));
    }

    /**
     * Host puro de recursos (https://api.bling.com.br, sem /Api/v3) — só o
     * revoke usa isso; token/recursos normais usam api_base_url com o
     * /Api/v3 junto (ver requestToken()/BlingClient).
     */
    private function apiHost(): string
    {
        return preg_replace('#/Api/v3/?$#', '', rtrim(config('services.bling.api_base_url'), '/'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistToken(array $payload): MarketplaceAccount
    {
        $expiresAt = now()->addSeconds((int) ($payload['expires_in'] ?? 21600));

        return MarketplaceAccount::query()->updateOrCreate(
            ['channel' => MarketplaceAccount::CHANNEL_BLING],
            [
                'status' => MarketplaceAccount::STATUS_CONNECTED,
                'access_token' => $payload['access_token'],
                'refresh_token' => $payload['refresh_token'] ?? null,
                'token_expires_at' => $expiresAt,
                'connected_at' => now(),
            ],
        );
    }
}

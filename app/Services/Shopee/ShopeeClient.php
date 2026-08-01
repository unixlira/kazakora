<?php

namespace App\Services\Shopee;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Shopee\Exceptions\ShopeeException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chamadas "shop-level" (autenticadas com access_token+shop_id) da Shopee
 * Open Platform v2. Assinatura HMAC-SHA256 sobre
 * partner_id+path+timestamp+access_token+shop_id, confirmada no PDF oficial
 * de autenticação — ver ShopeeAuthService pro fluxo de OAuth em si.
 */
class ShopeeClient
{
    public function __construct(private readonly ShopeeAuthService $auth) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $account = $this->authenticatedAccount();

        $response = Http::baseUrl(config('services.shopee.api_base_url'))
            ->get($path.'?'.http_build_query(array_merge($this->signedParams($path, $account), $query)));

        return $this->handleResponse($path, $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        $account = $this->authenticatedAccount();

        $response = Http::baseUrl(config('services.shopee.api_base_url'))
            ->post($path.'?'.http_build_query($this->signedParams($path, $account)), $data);

        return $this->handleResponse($path, $response);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{contents: string, filename: string}  $file
     * @return array<string, mixed>
     */
    public function postMultipart(string $path, array $data, array $file, string $fileFieldName = 'file'): array
    {
        $account = $this->authenticatedAccount();

        $request = Http::baseUrl(config('services.shopee.api_base_url'))
            ->attach($fileFieldName, $file['contents'], $file['filename']);

        foreach ($data as $key => $value) {
            $request = $request->attach($key, (string) $value);
        }

        $response = $request->post($path.'?'.http_build_query($this->signedParams($path, $account)));

        return $this->handleResponse($path, $response);
    }

    /**
     * Resposta binária (etiqueta/waybill) — a Shopee devolve o arquivo cru
     * no corpo quando o resultado não é um erro.
     *
     * @return array{contents: string, content_type: ?string}
     */
    public function getBinary(string $path, array $data = []): array
    {
        $account = $this->authenticatedAccount();

        $response = Http::baseUrl(config('services.shopee.api_base_url'))
            ->post($path.'?'.http_build_query($this->signedParams($path, $account)), $data);

        $this->log($path, $response);

        // Erro vem como JSON (Content-Type application/json); sucesso vem
        // como o binário do arquivo — só tenta decodificar como erro se
        // realmente parecer JSON.
        if (str_contains((string) $response->header('Content-Type'), 'json')) {
            $json = $response->json();

            if (! empty($json['error'])) {
                throw new ShopeeException($json['message'] ?? 'Erro ao baixar documento da Shopee.', $response->status(), ['body' => $json]);
            }
        }

        return [
            'contents' => $response->body(),
            'content_type' => $response->header('Content-Type'),
        ];
    }

    private function authenticatedAccount(): MarketplaceAccount
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();

        if (! $account) {
            throw new ShopeeException('Nenhuma loja da Shopee está conectada.');
        }

        return $this->auth->ensureValidToken($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function signedParams(string $path, MarketplaceAccount $account): array
    {
        $partnerId = (int) config('services.shopee.partner_id');
        $timestamp = now()->timestamp;
        $shopId = (int) $account->seller_id;
        $accessToken = $account->access_token;

        $baseString = "{$partnerId}{$path}{$timestamp}{$accessToken}{$shopId}";
        $sign = hash_hmac('sha256', $baseString, (string) config('services.shopee.partner_key'));

        return [
            'partner_id' => $partnerId,
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $sign,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(string $path, Response $response): array
    {
        $this->log($path, $response);

        $json = $response->json() ?? [];

        if ($response->failed() || ! empty($json['error'])) {
            throw new ShopeeException($json['message'] ?? "Erro na API da Shopee (HTTP {$response->status()}).", $response->status(), ['body' => $json]);
        }

        return $json;
    }

    private function log(string $path, Response $response): void
    {
        Log::channel('shopee')->info('shopee.request', ['path' => $path, 'status' => $response->status()]);
    }
}

<?php

namespace App\Services\Bling;

use App\Services\Bling\Exceptions\BlingException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper HTTP fino pra API de recursos do Bling (api.bling.com.br/Api/v3 —
 * diferente do host de OAuth, ver BlingAuthService). Injeta o token válido,
 * e se a chamada mesmo assim vier 401 (token expirou entre o ensureValidToken
 * e a chamada de verdade — janela real, ainda que pequena), renova 1x e
 * tenta de novo — mesmo comportamento documentado no SDK oficial em JS
 * ("um 401 nas chamadas de recurso dispara o refresh e uma nova tentativa").
 */
class BlingClient
{
    public function __construct(private readonly BlingAuthService $auth) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        return $this->request('GET', $uri, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $uri, array $data = []): array
    {
        return $this->request('POST', $uri, ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, array $options, bool $isRetry = false): array
    {
        $account = $this->auth->ensureValidToken($this->auth->currentAccount());

        $pending = Http::baseUrl(rtrim(config('services.bling.api_base_url'), '/'))
            ->withToken($account->access_token)
            ->acceptJson()
            ->timeout(20);

        $response = match ($method) {
            'GET' => $pending->get(ltrim($uri, '/'), $options['query'] ?? []),
            'POST' => $pending->asJson()->post(ltrim($uri, '/'), $options['json'] ?? []),
            default => throw new BlingException("Método HTTP não suportado pelo BlingClient: {$method}"),
        };

        if ($response->status() === 401 && ! $isRetry) {
            $this->auth->refreshToken($account);

            return $this->request($method, $uri, $options, isRetry: true);
        }

        $this->log($method, $uri, $response);

        return $this->handleResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(Response $response): array
    {
        if ($response->failed()) {
            $body = $response->json();

            throw new BlingException(
                $body['error']['message'] ?? $body['error']['description'] ?? "Erro na API do Bling (HTTP {$response->status()}).",
                $response->status(),
                ['body' => $body],
            );
        }

        return $response->json() ?? [];
    }

    private function log(string $method, string $uri, Response $response): void
    {
        Log::channel('bling')->info('bling.request', [
            'method' => $method,
            'uri' => $uri,
            'status' => $response->status(),
        ]);
    }
}

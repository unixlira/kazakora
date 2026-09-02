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
    /** 3 tentativas cobrem o pico normal de concorrência sem segurar o processo por muito tempo. */
    private const MAX_RATE_LIMIT_RETRIES = 3;

    /** ~0,7s, 1,4s, 2,1s — o teto é por SEGUNDO, então esperas curtas bastam. */
    private const RATE_LIMIT_BACKOFF_MICROSECONDS = 700000;

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
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function put(string $uri, array $data = []): array
    {
        return $this->request('PUT', $uri, ['json' => $data]);
    }

    public function post(string $uri, array $data = []): array
    {
        return $this->request('POST', $uri, ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, array $options, bool $isRetry = false, int $attempt = 1): array
    {
        $account = $this->auth->ensureValidToken($this->auth->currentAccount());

        $pending = Http::baseUrl(rtrim(config('services.bling.api_base_url'), '/'))
            ->withToken($account->access_token)
            ->acceptJson()
            ->timeout(20);

        $response = match ($method) {
            'GET' => $pending->get(ltrim($uri, '/'), $options['query'] ?? []),
            'POST' => $pending->asJson()->post(ltrim($uri, '/'), $options['json'] ?? []),
            'PUT' => $pending->asJson()->put(ltrim($uri, '/'), $options['json'] ?? []),
            default => throw new BlingException("Método HTTP não suportado pelo BlingClient: {$method}"),
        };

        if ($response->status() === 401 && ! $isRetry) {
            $this->auth->refreshToken($account);

            return $this->request($method, $uri, $options, isRetry: true);
        }

        // O teto do Bling é 3 req/s pra CONTA inteira (não por endpoint):
        // poll, emissão de nota, etiqueta e sincronização de produto
        // disputam a mesma fila, então 429 acontece o tempo todo em
        // qualquer rotina que faça mais de duas chamadas seguidas —
        // aconteceu ao vivo 2026-09-02 em bling:sync-fiscal, que morreu
        // em 7 dos 8 produtos. Espera e tenta de novo em vez de devolver
        // erro pra quem chamou: o próprio Bling manda tentar mais tarde.
        if ($response->status() === 429 && $attempt < self::MAX_RATE_LIMIT_RETRIES) {
            usleep(self::RATE_LIMIT_BACKOFF_MICROSECONDS * $attempt);

            return $this->request($method, $uri, $options, $isRetry, $attempt + 1);
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

            // Achado real 2026-09-02 (envio da nota 26759176098 à SEFAZ):
            // só `message` deixava o erro inútil pra diagnóstico — "Não
            // foi possível emitir a nota fiscal" e nada mais. O Bling
            // detalha o motivo real em `description` e em `fields[]`
            // (ex: campo fiscal faltando), e é isso que resolve o problema.
            $erro = $body['error'] ?? [];
            $partes = array_filter([
                $erro['message'] ?? null,
                $erro['description'] ?? null,
                collect($erro['fields'] ?? [])
                    ->map(fn ($campo) => trim(($campo['element'] ?? '').' '.($campo['msg'] ?? '')))
                    ->filter()
                    ->implode(' | ') ?: null,
            ]);

            throw new BlingException(
                $partes !== [] ? implode(' — ', $partes) : "Erro na API do Bling (HTTP {$response->status()}).",
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

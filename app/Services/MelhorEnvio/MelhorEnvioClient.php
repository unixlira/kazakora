<?php

namespace App\Services\MelhorEnvio;

use App\Services\MelhorEnvio\Exceptions\MelhorEnvioException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for the Melhor Envio API (OAuth2 — conta conectada via
 * MelhorEnvioAuthService, mesmo padrão de retry/log do MercadoLivreClient).
 */
class MelhorEnvioClient
{
    public function __construct(private readonly MelhorEnvioAuthService $auth)
    {
    }

    public function isConfigured(): bool
    {
        return $this->auth->currentToken() !== null;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function post(string $uri, array $data = []): array
    {
        return $this->request('POST', $uri, $data);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $uri, array $data): array
    {
        $token = $this->auth->ensureValidToken($this->auth->currentToken());

        $request = Http::baseUrl(config('services.melhorenvio.api_base_url'))
            ->withToken($token->access_token)
            ->withHeaders(['User-Agent' => config('melhorenvio.user_agent')])
            ->timeout((int) config('melhorenvio.timeout'))
            ->connectTimeout((int) config('melhorenvio.connect_timeout'))
            ->acceptJson();

        $response = $this->sendWithRetry($request, $method, ltrim($uri, '/'), $data);

        $this->log($method, $uri, $response);

        return $this->handleResponse($response);
    }

    private function sendWithRetry(PendingRequest $request, string $method, string $uri, array $data): Response
    {
        $maxAttempts = max(1, (int) config('melhorenvio.retry.times'));
        $baseDelayMs = (int) config('melhorenvio.retry.base_delay_ms');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $request->send($method, $uri, ['json' => $data]);
            } catch (ConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw new MelhorEnvioException("Falha de conexão com a API do Melhor Envio: {$exception->getMessage()}");
                }

                $this->backoff($baseDelayMs, $attempt);

                continue;
            }

            if ($response->serverError() && $attempt < $maxAttempts) {
                $this->backoff($baseDelayMs, $attempt);

                continue;
            }

            return $response;
        }

        return $response;
    }

    private function backoff(int $baseDelayMs, int $attempt): void
    {
        usleep($baseDelayMs * 1000 * (2 ** ($attempt - 1)));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function handleResponse(Response $response): array
    {
        if ($response->failed()) {
            throw new MelhorEnvioException(
                $response->json('message') ?? "Erro na API do Melhor Envio (HTTP {$response->status()}).",
                $response->status(),
            );
        }

        return $response->json() ?? [];
    }

    private function log(string $method, string $uri, Response $response): void
    {
        Log::channel(config('melhorenvio.log_channel'))->info('melhorenvio.request', [
            'method' => $method,
            'uri' => $uri,
            'status' => $response->status(),
        ]);
    }
}

<?php

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\Exceptions\RateLimitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for Mercado Livre's PUBLIC endpoints (product search,
 * listing_prices, etc.) — no OAuth token involved, unlike MercadoLivreClient.
 * Exists as a separate class so this keeps working before/without the store
 * ever connecting its Mercado Livre seller account.
 */
class MercadoLivrePublicClient
{
    /**
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        $request = Http::baseUrl(config('services.mercadolivre.api_base_url'))
            ->timeout((int) config('mercadolivre.timeout'))
            ->connectTimeout((int) config('mercadolivre.connect_timeout'))
            ->acceptJson();

        $response = $this->sendWithRetry($request, ltrim($uri, '/'), $query);

        $this->log($uri, $response);

        return $this->handleResponse($response);
    }

    private function sendWithRetry(PendingRequest $request, string $uri, array $query): Response
    {
        $maxAttempts = max(1, (int) config('mercadolivre.retry.times'));
        $baseDelayMs = (int) config('mercadolivre.retry.base_delay_ms');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $request->get($uri, $query);
            } catch (ConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw new MercadoLivreException(
                        "Falha de conexão com a API do Mercado Livre: {$exception->getMessage()}",
                        0,
                        ['uri' => $uri, 'attempt' => $attempt],
                    );
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
     * @return array<string, mixed>
     */
    private function handleResponse(Response $response): array
    {
        if ($response->status() === 429) {
            throw RateLimitException::make((int) $response->header('Retry-After') ?: null);
        }

        if ($response->failed()) {
            throw new MercadoLivreException(
                $response->json('message') ?? "Erro na API do Mercado Livre (HTTP {$response->status()}).",
                $response->status(),
                ['body' => $response->json()],
            );
        }

        return $response->json() ?? [];
    }

    private function log(string $uri, Response $response): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.public_request', [
            'uri' => $uri,
            'status' => $response->status(),
        ]);
    }
}

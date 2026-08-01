<?php

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\Exceptions\RateLimitException;
use App\Services\MercadoLivre\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client wrapper for the Mercado Livre API: injects the current
 * access token, retries transient failures, and maps error responses to
 * typed exceptions.
 */
class MercadoLivreClient
{
    public function __construct(private readonly MercadoLivreAuthService $auth) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        return $this->request('GET', $uri, ['query' => $query]);
    }

    /**
     * @return array<string, mixed>
     */
    public function post(string $uri, array $data = []): array
    {
        return $this->request('POST', $uri, ['json' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    public function put(string $uri, array $data = []): array
    {
        return $this->request('PUT', $uri, ['json' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $uri): array
    {
        return $this->request('DELETE', $uri);
    }

    /**
     * Upload de arquivo (multipart) — usado pelo envio de NF-e ao ML
     * (/packs/{id}/fiscal_documents). Sem retry automático de propósito:
     * reenviar silenciosamente um upload que já pode ter chegado do lado do
     * ML arrisca duplicar o envio da nota; o chamador decide se tenta de
     * novo.
     *
     * @param  array<string, mixed>  $data
     * @param  array{contents: string, filename: string}  $file
     * @return array<string, mixed>
     */
    public function postMultipart(string $uri, array $data, array $file, string $fileFieldName = 'file'): array
    {
        $token = $this->auth->ensureValidToken($this->auth->currentToken());

        $response = Http::baseUrl(config('services.mercadolivre.api_base_url'))
            ->withToken($token->access_token)
            ->timeout((int) config('mercadolivre.timeout'))
            ->connectTimeout((int) config('mercadolivre.connect_timeout'))
            ->acceptJson()
            ->attach($fileFieldName, $file['contents'], $file['filename'])
            ->post(ltrim($uri, '/'), $data);

        $this->log('POST', $uri, $response);

        return $this->handleResponse($response);
    }

    /**
     * Resposta binária (PDF/ZPL da etiqueta) — não passa por
     * handleResponse() porque o corpo não é JSON.
     *
     * @return array{contents: string, content_type: ?string}
     */
    public function getBinary(string $uri, array $query = []): array
    {
        $token = $this->auth->ensureValidToken($this->auth->currentToken());

        $response = Http::baseUrl(config('services.mercadolivre.api_base_url'))
            ->withToken($token->access_token)
            ->timeout((int) config('mercadolivre.timeout'))
            ->connectTimeout((int) config('mercadolivre.connect_timeout'))
            ->get(ltrim($uri, '/'), $query);

        $this->log('GET', $uri, $response);

        if ($response->status() === 401) {
            throw TokenExpiredException::make();
        }

        if ($response->status() === 429) {
            throw RateLimitException::make((int) $response->header('Retry-After') ?: null);
        }

        if ($response->failed()) {
            throw new MercadoLivreException(
                "Erro na API do Mercado Livre (HTTP {$response->status()}).",
                $response->status(),
                ['body' => $response->body()],
            );
        }

        return [
            'contents' => $response->body(),
            'content_type' => $response->header('Content-Type'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        $token = $this->auth->ensureValidToken($this->auth->currentToken());

        $request = Http::baseUrl(config('services.mercadolivre.api_base_url'))
            ->withToken($token->access_token)
            ->timeout((int) config('mercadolivre.timeout'))
            ->connectTimeout((int) config('mercadolivre.connect_timeout'))
            ->acceptJson();

        $response = $this->sendWithRetry($request, $method, ltrim($uri, '/'), $options);

        $this->log($method, $uri, $response);

        return $this->handleResponse($response);
    }

    private function sendWithRetry(PendingRequest $request, string $method, string $uri, array $options): Response
    {
        $maxAttempts = max(1, (int) config('mercadolivre.retry.times'));
        $baseDelayMs = (int) config('mercadolivre.retry.base_delay_ms');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $request->send($method, $uri, $options);
            } catch (ConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw new MercadoLivreException(
                        "Falha de conexão com a API do Mercado Livre: {$exception->getMessage()}",
                        0,
                        ['method' => $method, 'uri' => $uri, 'attempt' => $attempt],
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
        if ($response->status() === 401) {
            throw TokenExpiredException::make();
        }

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

    private function log(string $method, string $uri, Response $response): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.request', [
            'method' => $method,
            'uri' => $uri,
            'status' => $response->status(),
        ]);
    }
}

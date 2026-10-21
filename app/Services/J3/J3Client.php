<?php

namespace App\Services\J3;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * API da J3 Flex (plataforma MAGMA4 TMS), transportadora das entregas Flex
 * em São Paulo — pedido do Lira em 2026-10-21.
 *
 * Autenticação (manual "Integração API J3" + Swagger em
 * https://api.j3tms.com.br/api): o painel da J3 (Integrações > Token) gera
 * uma CHAVE de API, que não é o Bearer. Ela é trocada em
 * `POST /api/auth/changeKey?key=...` por um accessToken JWT que vale 24h.
 * O token fica em cache por 23h e, se a J3 responder 401 antes disso, é
 * renovado uma vez na hora.
 */
class J3Client
{
    private const CACHE_TOKEN = 'j3.access_token';

    public function isConfigured(): bool
    {
        return filled(config('services.j3.api_key'));
    }

    /** CEP dentro da área atendida pela J3 (GET /api/regions/coverage/{cep}). */
    public function cepAtendido(string $cep): bool
    {
        $cep = preg_replace('/\D/', '', $cep) ?? '';

        return (bool) $this->get("/api/regions/coverage/{$cep}")->json('inCoverage');
    }

    /**
     * Pedido já criado na J3 para esse envio, ou null (404). Evita criar um
     * segundo pedido quando a tentativa anterior caiu depois do POST.
     *
     * @return array<string, mixed>|null
     */
    public function pedidoPorEnvio(string $shippingIntegrationId): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/api/orders/shipping-integration/'.rawurlencode($shippingIntegrationId)));

        if ($response->status() === 404) {
            return null;
        }

        $this->falharSeErro($response, 'buscar pedido');

        return $response->json() ?: null;
    }

    /**
     * POST /api/orders (CreateIntegrationOrderDto). Devolve o
     * CreateIntegrationOrderResponseDto: success, trackingNumber,
     * orderCode, stampUrl (PDF da etiqueta) ou error.
     *
     * @param  array<string, mixed>  $pedido
     * @return array<string, mixed>
     */
    public function criarPedido(array $pedido): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/api/orders', $pedido));

        $corpo = $response->json() ?? [];

        if ($response->failed() || ! ($corpo['success'] ?? false)) {
            throw new RuntimeException(sprintf(
                'J3 recusou o pedido (HTTP %d): %s',
                $response->status(),
                $corpo['error'] ?? $corpo['message'] ?? mb_substr($response->body(), 0, 300),
            ));
        }

        return $corpo;
    }

    /** URL do PDF da etiqueta pelo rastreio da J3 (GET /api/orders/stamps/{tracking}). */
    public function urlDaEtiqueta(string $trackingNumber): string
    {
        $url = $this->get('/api/orders/stamps/'.rawurlencode($trackingNumber))->json('stampUrl');

        if (blank($url)) {
            throw new RuntimeException("J3 não devolveu a etiqueta do rastreio {$trackingNumber}.");
        }

        return $url;
    }

    /** Baixa o PDF da etiqueta (o link pode ser de outro domínio, sem token). */
    public function baixarEtiqueta(string $url): string
    {
        $response = Http::timeout($this->timeout())->retry(2, 500, throw: false)->get($url);

        $this->falharSeErro($response, 'baixar etiqueta');

        if (! str_starts_with($response->body(), '%PDF-')) {
            throw new RuntimeException('J3 devolveu uma etiqueta que não é PDF.');
        }

        return $response->body();
    }

    private function get(string $caminho): Response
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($caminho));

        $this->falharSeErro($response, "GET {$caminho}");

        return $response;
    }

    /** @param  callable(PendingRequest): Response  $chamada */
    private function send(callable $chamada): Response
    {
        $response = $chamada($this->http($this->token()));

        if ($response->status() === 401) {
            Cache::forget(self::CACHE_TOKEN);
            $response = $chamada($this->http($this->token()));
        }

        return $response;
    }

    private function http(string $token): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($token)
            ->acceptJson()
            ->timeout($this->timeout());
    }

    private function token(): string
    {
        return Cache::remember(self::CACHE_TOKEN, now()->addHours(23), function () {
            if (! $this->isConfigured()) {
                throw new RuntimeException('J3 sem chave de API (J3_API_KEY no .env).');
            }

            $response = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->timeout($this->timeout())
                ->post('/api/auth/changeKey?'.http_build_query(['key' => config('services.j3.api_key')]));

            $token = $response->json('accessToken');

            if ($response->failed() || blank($token)) {
                throw new RuntimeException("J3 recusou a chave de API (HTTP {$response->status()}).");
            }

            return $token;
        });
    }

    private function falharSeErro(Response $response, string $oQue): void
    {
        if ($response->failed()) {
            throw new RuntimeException(sprintf('J3 falhou ao %s (HTTP %d): %s', $oQue, $response->status(), mb_substr($response->body(), 0, 300)));
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.j3.base_url', 'https://api.j3tms.com.br'), '/');
    }

    private function timeout(): int
    {
        return (int) config('services.j3.timeout', 20);
    }
}

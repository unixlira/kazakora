<?php

namespace App\Services\MercadoPago;

use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP + orquestração de pagamentos via Mercado Pago (cartão, Pix e
 * boleto) — mesmo padrão de retry/log dos outros clients do projeto
 * (MercadoLivreClient/MelhorEnvioClient). Autenticação é um Access Token
 * direto (Bearer), sem OAuth — o token já é o segredo da própria conta,
 * igual à chave secreta do Stripe.
 */
class MercadoPagoPaymentService
{
    public function isConfigured(): bool
    {
        return filled(config('services.mercadopago.access_token'));
    }

    /**
     * Pix no Mercado Pago não tem etapa de confirmação no front-end — a
     * resposta já vem com o QR code (copia-e-cola) e a imagem em base64
     * prontos pra mostrar, ao contrário do Stripe (que exige montar o
     * Payment Element e só recebe o QR depois de confirmPayment()).
     */
    public function createPixPayment(float $amount, array $payer, array $metadata, string $idempotencyKey): array
    {
        return $this->request('POST', 'v1/payments', [
            'transaction_amount' => $amount,
            'payment_method_id' => 'pix',
            'description' => $metadata['description'] ?? 'Pedido KazaKora',
            'payer' => $payer,
            'metadata' => $metadata,
            'date_of_expiration' => now()->addMinutes(30)->toIso8601String(),
        ], $idempotencyKey);
    }

    /**
     * Cartão a partir de um token gerado no front-end pelo SDK JS do Mercado
     * Pago (Card Payment Brick) — o backend nunca vê o número do cartão,
     * só o token, igual ao Stripe. capture=false autoriza sem capturar
     * (mesmo uso que o Stripe: dá pra cancelar sem tirar dinheiro se a
     * outra parte de um pagamento dividido falhar).
     */
    public function createCardPayment(
        float $amount,
        string $token,
        int $installments,
        string $paymentMethodId,
        ?string $issuerId,
        array $payer,
        array $metadata,
        string $idempotencyKey,
        bool $capture = true,
    ): array {
        return $this->request('POST', 'v1/payments', array_filter([
            'transaction_amount' => $amount,
            'token' => $token,
            'installments' => $installments,
            'payment_method_id' => $paymentMethodId,
            'issuer_id' => $issuerId,
            'capture' => $capture,
            'payer' => $payer,
            'metadata' => $metadata,
        ], fn ($value) => $value !== null), $idempotencyKey);
    }

    public function createBoletoPayment(float $amount, array $payer, array $metadata, string $idempotencyKey): array
    {
        return $this->request('POST', 'v1/payments', [
            'transaction_amount' => $amount,
            'payment_method_id' => 'bolbradesco',
            'description' => $metadata['description'] ?? 'Pedido KazaKora',
            'payer' => $payer,
            'metadata' => $metadata,
        ], $idempotencyKey);
    }

    public function retrieve(string $paymentId): array
    {
        return $this->request('GET', "v1/payments/{$paymentId}");
    }

    public function capture(string $paymentId): array
    {
        return $this->request('PUT', "v1/payments/{$paymentId}", ['capture' => true]);
    }

    /** Só funciona antes da captura — depois disso precisa de refund(). */
    public function cancel(string $paymentId): array
    {
        return $this->request('PUT', "v1/payments/{$paymentId}", ['status' => 'cancelled']);
    }

    public function refund(string $paymentId): array
    {
        return $this->request('POST', "v1/payments/{$paymentId}/refunds");
    }

    private function request(string $method, string $uri, array $data = [], ?string $idempotencyKey = null): array
    {
        if (! $this->isConfigured()) {
            throw new MercadoPagoException('Mercado Pago não configurado.');
        }

        $request = Http::baseUrl(config('services.mercadopago.api_base_url'))
            ->withToken(config('services.mercadopago.access_token'))
            ->timeout((int) config('mercadopago.timeout'))
            ->connectTimeout((int) config('mercadopago.connect_timeout'))
            ->acceptJson();

        if ($idempotencyKey) {
            $request = $request->withHeaders(['X-Idempotency-Key' => $idempotencyKey]);
        }

        $response = $this->sendWithRetry($request, $method, ltrim($uri, '/'), $data);

        $this->log($method, $uri, $response);

        return $this->handleResponse($response);
    }

    private function sendWithRetry(PendingRequest $request, string $method, string $uri, array $data): Response
    {
        $maxAttempts = max(1, (int) config('mercadopago.retry.times'));
        $baseDelayMs = (int) config('mercadopago.retry.base_delay_ms');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $method === 'GET'
                    ? $request->get($uri)
                    : $request->send($method, $uri, ['json' => $data]);
            } catch (ConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw new MercadoPagoException("Falha de conexão com a API do Mercado Pago: {$exception->getMessage()}");
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

    private function handleResponse(Response $response): array
    {
        if ($response->failed()) {
            throw new MercadoPagoException(
                $response->json('message') ?? "Erro na API do Mercado Pago (HTTP {$response->status()}).",
                $response->status(),
                ['body' => $response->json()],
            );
        }

        return $response->json() ?? [];
    }

    private function log(string $method, string $uri, Response $response): void
    {
        Log::channel(config('mercadopago.log_channel'))->info('mercadopago.request', [
            'method' => $method,
            'uri' => $uri,
            'status' => $response->status(),
        ]);
    }
}

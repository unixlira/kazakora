<?php

namespace App\Services\MercadoPago;

use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP + orquestração de pagamentos via Mercado Pago. Híbrido por
 * necessidade real, não por escolha: cartão usa a API de Orders (Checkout
 * Transparente) — a aplicação só está inscrita nos tópicos de webhook
 * `order`/fraude/contestação, então é essa API que faz os webhooks
 * chegarem. Pix usa a API de Payments clássica (v1/payments) porque a API
 * de Orders rejeita "bank_transfer" como payment_method.type válido nessa
 * conta, mesmo com Pix ativo — ver createPixPayment(). Mesmo padrão de
 * retry/log dos outros clients do projeto (MercadoLivreClient/
 * MelhorEnvioClient). Autenticação é um Access Token direto (Bearer), sem
 * OAuth.
 */
class MercadoPagoPaymentService
{
    public function isConfigured(): bool
    {
        return filled(CredenciaisCheckout::accessToken());
    }

    /**
     * Cria e processa uma Order em modo automático — cartão (com token do
     * Card Payment Brick) e Pix usam o mesmo endpoint, só muda o formato de
     * `payment_method` dentro de `transactions.payments[0]`. HTTP 402
     * ("Order criada mas transação falhou") é tratado como resposta válida
     * aqui, não como erro — o corpo ainda traz o status real do pagamento
     * (ex: cartão recusado), que o chamador decide como tratar.
     */
    public function createOrder(array $payload, string $idempotencyKey): array
    {
        $result = $this->request('POST', 'v1/orders', $payload, $idempotencyKey, toleratedStatuses: [402]);

        // HTTP 402 ("order criada mas transação falhou", ex: cartão
        // recusado) devolve a order real dentro de "data" em vez do objeto
        // direto na raiz — confirmado testando um cartão de teste recusado.
        // Sucesso (201) nunca tem essa chave, então isso não afeta esse caso.
        return $result['data'] ?? $result;
    }

    public function retrieveOrder(string $orderId): array
    {
        return $this->request('GET', "v1/orders/{$orderId}");
    }

    /** Só captura o valor total — a API de Orders não suporta captura parcial. */
    public function captureOrder(string $orderId): array
    {
        return $this->request('POST', "v1/orders/{$orderId}/capture");
    }

    /** Só funciona com a order ainda em status "created" (antes de processar). */
    public function cancelOrder(string $orderId): array
    {
        return $this->request('POST', "v1/orders/{$orderId}/cancel");
    }

    /**
     * Só funciona com a order em status "processed". Sem $amount, estorna o valor total.
     * $idempotencyKey: a mesma chave numa nova tentativa não estorna duas vezes.
     */
    public function refundOrder(string $orderId, ?float $amount = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', "v1/orders/{$orderId}/refund", $amount !== null ? ['amount' => $amount] : [], $idempotencyKey);
    }

    /**
     * Pix passa pela API de Payments clássica (v1/payments), não pela de
     * Orders — confirmado direto na API: essa conta tem Pix ativo
     * (/v1/payment_methods lista "pix" com status active), mas a API de
     * Orders rejeita "bank_transfer" como type válido pra ela mesmo assim
     * (erro real: "value must be one of credit_card, debit_card,
     * account_money, digital_currency, wallet" — sem bank_transfer). Um Pix
     * de teste real via v1/payments funcionou normalmente na mesma conta,
     * então é uma limitação específica da API de Orders pra Pix nessa
     * conta/momento, não um bug daqui. Cartão continua na API de Orders
     * (funciona e os webhooks chegam). Sem webhook pro tópico "payment"
     * nessa aplicação, a confirmação do Pix depende só do polling — que já
     * é o mecanismo usado de qualquer forma enquanto o QR não é pago.
     */
    public function createPixPayment(float $amount, array $payer, string $externalReference, string $idempotencyKey): array
    {
        return $this->request('POST', 'v1/payments', [
            'transaction_amount' => $amount,
            'payment_method_id' => 'pix',
            'description' => "Pedido {$externalReference} - KazaKora",
            'external_reference' => $externalReference,
            'payer' => $payer,
            'date_of_expiration' => now()->addMinutes(10)->format('Y-m-d\TH:i:s.vP'),
        ], $idempotencyKey);
    }

    /**
     * Boleto (pedido 2026-10-14) também pela API de Payments clássica, como
     * o Pix. O id ativo na conta é "bolbradesco" (conferido em 2026-10-14 em
     * GET /v1/payment_methods: único meio "ticket", de R$ 4 a R$ 100.000).
     * O Mercado Pago exige do pagador nome, sobrenome, CPF e endereço
     * completo. Vence às 23:59:59 (São Paulo) de hoje + N dias corridos
     * (config loja.boleto_dias_vencimento).
     *
     * @param  array<string, mixed>  $payer  email, first_name, last_name, identification, address
     */
    public function createBoletoPayment(float $amount, array $payer, string $externalReference, string $idempotencyKey): array
    {
        return $this->request('POST', 'v1/payments', [
            'transaction_amount' => $amount,
            'payment_method_id' => 'bolbradesco',
            'description' => "Pedido {$externalReference} - KazaKora",
            'external_reference' => $externalReference,
            'payer' => $payer,
            'date_of_expiration' => self::vencimentoDoBoleto()->format('Y-m-d\TH:i:s.vP'),
        ], $idempotencyKey);
    }

    public static function vencimentoDoBoleto(): \Illuminate\Support\Carbon
    {
        return now('America/Sao_Paulo')
            ->addDays(max(1, (int) config('loja.boleto_dias_vencimento', 3)))
            ->setTime(23, 59, 59);
    }

    /**
     * Linha digitável, código de barras, PDF e vencimento de um boleto na
     * resposta do Mercado Pago. A linha digitável vem em
     * transaction_details.digitable_line nas respostas novas; quando só vier
     * o código de barras (44 dígitos), ela é montada a partir dele (regra
     * FEBRABAN, mesmo resultado que o banco imprime).
     *
     * @return array{linhaDigitavel: ?string, codigoBarras: ?string, pdfUrl: ?string, vencimento: ?string}
     */
    public static function dadosDoBoleto(array $mpPayment): array
    {
        $detalhes = $mpPayment['transaction_details'] ?? [];
        $codigoBarras = $detalhes['barcode']['content'] ?? $mpPayment['barcode']['content'] ?? null;
        $linha = $detalhes['digitable_line'] ?? null;

        if (! $linha && $codigoBarras && strlen(preg_replace('/\D/', '', $codigoBarras)) === 44) {
            $linha = self::linhaDigitavelDoCodigoDeBarras(preg_replace('/\D/', '', $codigoBarras));
        }

        return [
            'linhaDigitavel' => $linha ? (string) $linha : null,
            'codigoBarras' => $codigoBarras ? (string) $codigoBarras : null,
            'pdfUrl' => $detalhes['external_resource_url'] ?? null,
            'vencimento' => $mpPayment['date_of_expiration'] ?? null,
        ];
    }

    /** Código de barras de boleto bancário (44 dígitos) → linha digitável (47 dígitos, formatada). */
    public static function linhaDigitavelDoCodigoDeBarras(string $codigo): string
    {
        $livre = substr($codigo, 19, 25);
        $campo1 = substr($codigo, 0, 4).substr($livre, 0, 5);
        $campo2 = substr($livre, 5, 10);
        $campo3 = substr($livre, 15, 10);
        $campo1 .= self::modulo10($campo1);
        $campo2 .= self::modulo10($campo2);
        $campo3 .= self::modulo10($campo3);

        return sprintf(
            '%s.%s %s.%s %s.%s %s %s',
            substr($campo1, 0, 5), substr($campo1, 5),
            substr($campo2, 0, 5), substr($campo2, 5),
            substr($campo3, 0, 5), substr($campo3, 5),
            $codigo[4],
            substr($codigo, 5, 14),
        );
    }

    private static function modulo10(string $numero): int
    {
        $soma = 0;
        $peso = 2;
        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $produto = (int) $numero[$i] * $peso;
            $soma += intdiv($produto, 10) + $produto % 10;
            $peso = $peso === 2 ? 1 : 2;
        }

        return (10 - $soma % 10) % 10;
    }

    public function retrievePayment(string $paymentId): array
    {
        return $this->request('GET', "v1/payments/{$paymentId}");
    }

    public function cancelPayment(string $paymentId): array
    {
        return $this->request('PUT', "v1/payments/{$paymentId}", ['status' => 'cancelled']);
    }

    /** $idempotencyKey: a mesma chave numa nova tentativa não estorna duas vezes. */
    public function refundPayment(string $paymentId, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', "v1/payments/{$paymentId}/refunds", [], $idempotencyKey);
    }

    private function request(
        string $method,
        string $uri,
        array $data = [],
        ?string $idempotencyKey = null,
        array $toleratedStatuses = [],
    ): array {
        if (! $this->isConfigured()) {
            throw new MercadoPagoException('Mercado Pago não configurado.');
        }

        $request = Http::baseUrl(config('services.mercadopago.api_base_url'))
            ->withToken(CredenciaisCheckout::accessToken())
            ->timeout((int) config('mercadopago.timeout'))
            ->connectTimeout((int) config('mercadopago.connect_timeout'))
            ->acceptJson();

        if ($idempotencyKey) {
            $request = $request->withHeaders(['X-Idempotency-Key' => $idempotencyKey]);
        }

        $response = $this->sendWithRetry($request, $method, ltrim($uri, '/'), $data);

        $this->log($method, $uri, $response);

        return $this->handleResponse($response, $toleratedStatuses);
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

    private function handleResponse(Response $response, array $toleratedStatuses = []): array
    {
        if ($response->failed() && ! in_array($response->status(), $toleratedStatuses, true)) {
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

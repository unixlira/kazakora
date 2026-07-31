<?php

namespace App\Services\Stripe;

use App\Modules\Checkout\Models\Payment;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripePaymentService
{
    public const PIX_EXPIRES_AFTER_SECONDS = 1800;

    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * Cria um PaymentIntent para uma fatia do pagamento. Cartão usa captura
     * manual (autoriza sem tirar o dinheiro ainda, pra dar pra cancelar sem
     * captura se a outra parte do split falhar). Pix e boleto não têm essa
     * fase de "autorizar sem capturar" no Stripe — uma vez confirmados, o
     * valor já é transferido — por isso NUNCA devem ser a primeira parte
     * criada num split; ver orquestração em CheckoutController.
     */
    public function createIntent(string $methodType, float $amount, array $metadata = [], ?string $idempotencyKey = null): PaymentIntent
    {
        $stripeMethod = $this->stripeMethodType($methodType);

        $payload = [
            'amount' => $this->toCents($amount),
            'currency' => 'brl',
            'payment_method_types' => [$stripeMethod],
            'metadata' => $metadata,
        ];

        if ($stripeMethod === 'card') {
            $payload['capture_method'] = 'manual';
        }

        if ($stripeMethod === 'pix') {
            $payload['payment_method_options'] = [
                'pix' => ['expires_after_seconds' => self::PIX_EXPIRES_AFTER_SECONDS],
            ];
        }

        $options = $idempotencyKey ? ['idempotency_key' => $idempotencyKey] : [];

        Log::channel('stripe')->info('stripe.payment_intent.creating', [
            'order_id' => $metadata['order_id'] ?? null,
            'method_type' => $stripeMethod,
            'amount_cents' => $payload['amount'],
            'idempotency_key' => $idempotencyKey,
        ]);

        try {
            $intent = $this->client()->paymentIntents->create($payload, $options);
        } catch (ApiErrorException $exception) {
            Log::channel('stripe')->warning('stripe.payment_intent.create_failed', [
                'order_id' => $metadata['order_id'] ?? null,
                'method_type' => $stripeMethod,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::channel('stripe')->info('stripe.payment_intent.created', [
            'order_id' => $metadata['order_id'] ?? null,
            'payment_intent_id' => $intent->id,
        ]);

        return $intent;
    }

    public function capture(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->capture($paymentIntentId);
    }

    public function cancel(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->cancel($paymentIntentId);
    }

    public function retrieve(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->retrieve($paymentIntentId);
    }

    /**
     * Reembolsa um PaymentIntent já capturado (dinheiro já saiu — devolve
     * de verdade via API, não é o mesmo que cancel(), que só funciona antes
     * da captura).
     */
    public function refund(string $paymentIntentId): Refund
    {
        Log::channel('stripe')->info('stripe.refund.creating', ['payment_intent_id' => $paymentIntentId]);

        try {
            $refund = $this->client()->refunds->create(['payment_intent' => $paymentIntentId]);
        } catch (ApiErrorException $exception) {
            Log::channel('stripe')->warning('stripe.refund.failed', [
                'payment_intent_id' => $paymentIntentId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::channel('stripe')->info('stripe.refund.created', [
            'payment_intent_id' => $paymentIntentId,
            'refund_id' => $refund->id,
        ]);

        return $refund;
    }

    /**
     * @throws SignatureVerificationException|UnexpectedValueException
     */
    public function constructWebhookEvent(string $payload, string $signature): \Stripe\Event
    {
        return Webhook::constructEvent($payload, $signature, config('services.stripe.webhook_secret'));
    }

    private function client(): StripeClient
    {
        if (! $this->isConfigured()) {
            throw new StripeNotConfiguredException();
        }

        return $this->client ??= new StripeClient(config('services.stripe.secret'));
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function stripeMethodType(string $methodType): string
    {
        return match ($methodType) {
            Payment::METHOD_PIX => 'pix',
            Payment::METHOD_BOLETO => 'boleto',
            default => 'card',
        };
    }
}

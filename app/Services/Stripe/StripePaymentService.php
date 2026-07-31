<?php

namespace App\Services\Stripe;

use App\Modules\Checkout\Models\Payment;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripePaymentService
{
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
    public function createIntent(string $methodType, float $amount, array $metadata = []): PaymentIntent
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

        return $this->client()->paymentIntents->create($payload);
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

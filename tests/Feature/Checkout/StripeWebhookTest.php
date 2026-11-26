<?php

namespace Tests\Feature\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        $response = $this->postJson('/api/stripe/webhook', ['type' => 'payment_intent.succeeded'], [
            'Stripe-Signature' => 'invalid',
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_sem_segredo_configurado_recusa(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->postJson('/api/stripe/webhook', ['type' => 'payment_intent.succeeded'], ['Stripe-Signature' => 't=1,v1=abc'])
            ->assertStatus(401);
    }
}

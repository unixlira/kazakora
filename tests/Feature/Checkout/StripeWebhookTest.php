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

        $response->assertStatus(400);
    }
}

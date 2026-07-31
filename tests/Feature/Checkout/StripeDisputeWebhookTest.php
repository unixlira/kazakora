<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Event;
use Tests\TestCase;

class StripeDisputeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispute_event_flags_the_order_without_touching_its_status(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);
        $order->payments()->create([
            'stripe_payment_intent_id' => 'pi_disputed_123',
            'method_type' => Payment::METHOD_CARD,
            'amount' => 100,
            'status' => Payment::STATUS_CAPTURED,
        ]);

        $event = Event::constructFrom([
            'id' => 'evt_test_dispute',
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'object' => 'dispute',
                    'id' => 'dp_test_123',
                    'payment_intent' => 'pi_disputed_123',
                    'reason' => 'fraudulent',
                ],
            ],
        ]);

        $this->mock(StripePaymentService::class, function ($mock) use ($event) {
            $mock->shouldReceive('constructWebhookEvent')->andReturn($event);
        });

        $response = $this->postJson('/api/stripe/webhook', [], ['Stripe-Signature' => 'anything']);

        $response->assertOk();
        $order->refresh();
        $this->assertNotNull($order->disputed_at);
        $this->assertSame(Order::STATUS_PAID, $order->status);
    }
}

<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Tests\TestCase;

class PaymentStatusPollingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_AWAITING_PAYMENT,
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
    }

    public function test_status_endpoint_reports_pending_while_stripe_has_not_confirmed_yet(): void
    {
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('retrieve')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_1', 'status' => 'requires_payment_method']));
        });

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->makeOrder($user);
        $order->payments()->create(['stripe_payment_intent_id' => 'pi_1', 'method_type' => Payment::METHOD_CARD, 'amount' => 100, 'status' => Payment::STATUS_REQUIRES_CONFIRMATION]);

        $response = $this->actingAs($user)->getJson("/finalizacao/{$order->id}/status");

        $response->assertOk()->assertJson(['status' => Order::STATUS_AWAITING_PAYMENT, 'redirect' => null]);
    }

    public function test_status_endpoint_confirms_and_marks_paid_once_stripe_authorizes(): void
    {
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('retrieve')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_1', 'status' => 'requires_capture']));
            $mock->shouldReceive('capture')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_1', 'status' => 'succeeded']));
        });

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->makeOrder($user);
        $order->payments()->create(['stripe_payment_intent_id' => 'pi_1', 'method_type' => Payment::METHOD_CARD, 'amount' => 100, 'status' => Payment::STATUS_REQUIRES_CONFIRMATION]);

        $response = $this->actingAs($user)->getJson("/finalizacao/{$order->id}/status");

        $response->assertOk()->assertJson(['status' => Order::STATUS_PAID]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);
    }

    public function test_status_endpoint_reverts_order_to_pending_when_a_payment_is_canceled(): void
    {
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('retrieve')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_1', 'status' => 'canceled']));
        });

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->makeOrder($user);
        $order->payments()->create(['stripe_payment_intent_id' => 'pi_1', 'method_type' => Payment::METHOD_CARD, 'amount' => 100, 'status' => Payment::STATUS_REQUIRES_CONFIRMATION]);

        $response = $this->actingAs($user)->getJson("/finalizacao/{$order->id}/status");

        $response->assertOk()->assertJson(['status' => Order::STATUS_PENDING]);
    }

    public function test_status_endpoint_rejects_a_different_users_order(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $intruder = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->makeOrder($owner);

        $this->actingAs($intruder)->getJson("/finalizacao/{$order->id}/status")->assertForbidden();
    }
}

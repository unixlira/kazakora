<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Stripe\PaymentIntent;
use Tests\TestCase;

class SplitPaymentFinalizerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

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

    public function test_order_is_not_finalized_while_any_payment_is_not_authorized(): void
    {
        $order = $this->makeOrder();
        $order->payments()->create(['stripe_payment_intent_id' => 'pi_1', 'method_type' => Payment::METHOD_CARD, 'amount' => 60, 'status' => Payment::STATUS_AUTHORIZED]);
        $order->payments()->create(['stripe_payment_intent_id' => 'pi_2', 'method_type' => Payment::METHOD_PIX, 'amount' => 40, 'status' => Payment::STATUS_REQUIRES_CONFIRMATION]);

        $finalizer = app(OrderPaymentFinalizer::class);
        $result = $finalizer->finalize($order->fresh());

        $this->assertFalse($result);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_AWAITING_PAYMENT]);
    }

    public function test_order_is_finalized_and_captured_once_both_payments_are_authorized(): void
    {
        Queue::fake();

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('capture')->twice()->andReturn(PaymentIntent::constructFrom(['id' => 'pi', 'status' => 'succeeded']));
        });

        $order = $this->makeOrder();
        $order->payments()->create(['stripe_payment_intent_id' => 'pi_1', 'method_type' => Payment::METHOD_CARD, 'amount' => 60, 'status' => Payment::STATUS_AUTHORIZED]);
        $order->payments()->create(['stripe_payment_intent_id' => 'pi_2', 'method_type' => Payment::METHOD_CARD, 'amount' => 40, 'status' => Payment::STATUS_AUTHORIZED]);

        $finalizer = app(OrderPaymentFinalizer::class);
        $result = $finalizer->finalize($order->fresh());

        $this->assertTrue($result);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);
        $this->assertDatabaseHas('payments', ['stripe_payment_intent_id' => 'pi_1', 'status' => Payment::STATUS_CAPTURED]);
        $this->assertDatabaseHas('payments', ['stripe_payment_intent_id' => 'pi_2', 'status' => Payment::STATUS_CAPTURED]);
        Queue::assertPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $order->id);
    }

    public function test_failure_of_one_payment_cancels_the_other_authorized_payment_without_capturing(): void
    {
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('cancel')->once()->andReturn(PaymentIntent::constructFrom(['id' => 'pi', 'status' => 'canceled']));
        });

        $order = $this->makeOrder();
        $authorized = $order->payments()->create(['stripe_payment_intent_id' => 'pi_1', 'method_type' => Payment::METHOD_CARD, 'amount' => 60, 'status' => Payment::STATUS_AUTHORIZED]);
        $failed = $order->payments()->create(['stripe_payment_intent_id' => 'pi_2', 'method_type' => Payment::METHOD_PIX, 'amount' => 40, 'status' => Payment::STATUS_FAILED]);

        $finalizer = app(OrderPaymentFinalizer::class);
        $finalizer->cancelSiblingsAfterFailure($order->fresh(), $failed);

        $this->assertDatabaseHas('payments', ['id' => $authorized->id, 'status' => Payment::STATUS_CANCELED]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PENDING]);
    }
}

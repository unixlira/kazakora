<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Tests\TestCase;

class PendingOrderResumeTest extends TestCase
{
    use RefreshDatabase;

    private function mockStripe(): void
    {
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createIntent')->andReturn(
                PaymentIntent::constructFrom(['id' => 'pi_test_'.uniqid(), 'client_secret' => 'secret_test_'.uniqid()])
            );
            $mock->shouldReceive('retrieve')->andReturn(
                PaymentIntent::constructFrom(['id' => 'pi_test', 'status' => 'requires_payment_method', 'client_secret' => 'secret_test_resume'])
            );
        });
    }

    private function reachPaymentStep(User $user, Product $product, ShippingMethod $shippingMethod): void
    {
        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($user)->post('/finalizacao/entrega', [
            'shipping_method_id' => $shippingMethod->id,
            'new_address' => [
                'recipient_name' => 'Cliente Teste',
                'phone' => '11999999999',
                'zip' => '01000-000',
                'street' => 'Rua Teste',
                'number' => '100',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
        ]);
    }

    public function test_submitting_payment_twice_reuses_the_same_order_instead_of_duplicating(): void
    {
        $this->mockStripe();

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);

        $this->reachPaymentStep($user, $product, $shippingMethod);

        $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'card', 'terms_accepted' => true])->assertOk();
        $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'card', 'terms_accepted' => true])->assertOk();

        $this->assertSame(1, Order::where('user_id', $user->id)->count());
        $this->assertSame(1, Order::first()->payments()->count());
    }

    public function test_reloading_the_payment_page_resumes_the_pending_order(): void
    {
        $this->mockStripe();

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);

        $this->reachPaymentStep($user, $product, $shippingMethod);
        $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'card', 'terms_accepted' => true]);

        $order = Order::where('user_id', $user->id)->first();

        $response = $this->actingAs($user)->get('/finalizacao/pagamento');

        $response->assertInertia(fn ($page) => $page
            ->where('order.id', $order->id)
            ->has('clientSecret'));

        $this->assertSame(1, Order::where('user_id', $user->id)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Stripe\PaymentIntent;
use Tests\TestCase;

class CartCheckoutQuantityDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_total_reflects_the_applicable_quantity_discount_tier(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 50, 'is_active' => true]);
        $product->quantityDiscounts()->create(['min_quantity' => 5, 'discount_percentage' => 10]);

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 5])
            ->assertRedirect();

        $response = $this->actingAs($user)->get('/carrinho');

        // 5 unidades a R$90 (10% OFF a partir de 5) = R$450, não R$500.
        $response->assertInertia(fn ($page) => $page
            ->where('total', 450)
            ->where('items.0.subtotal', 450));
    }

    public function test_checkout_charges_the_quantity_discount_price_on_the_order_item(): void
    {
        Mail::fake();
        Queue::fake();
        $this->mockStripe();

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 50, 'is_active' => true]);
        $product->quantityDiscounts()->create(['min_quantity' => 5, 'discount_percentage' => 10]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 5]);

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
        ])->assertRedirect(route('finalizacao.pagamento'));

        $this->actingAs($user)->post('/finalizacao/pagamento', [
            'payment_method' => 'card',
            'terms_accepted' => true,
        ]);

        $order = Order::latest('id')->first();

        $this->actingAs($user)->get("/finalizacao/{$order->id}/status")->assertOk()->assertJson(['status' => 'paid']);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'quantity' => 5,
            'product_price' => 90,
            'subtotal' => 450,
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);

        Queue::assertPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $order->id);
    }

    private function mockStripe(): void
    {
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createIntent')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test_'.uniqid(), 'client_secret' => 'secret_test_'.uniqid()]));
            $mock->shouldReceive('retrieve')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test', 'status' => 'requires_capture']));
            $mock->shouldReceive('capture')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test', 'status' => 'succeeded']));
            $mock->shouldReceive('cancel')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test', 'status' => 'canceled']));
        });
    }
}

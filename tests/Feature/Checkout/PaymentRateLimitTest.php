<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Tests\TestCase;

class PaymentRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_creation_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 100, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createIntent')->andReturnUsing(
                fn () => PaymentIntent::constructFrom(['id' => 'pi_test_'.uniqid(), 'client_secret' => 'secret_test_'.uniqid()])
            );
        });

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

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->post('/finalizacao/pagamento', [
                'payment_method' => 'card',
                'terms_accepted' => true,
            ])->assertOk();
        }

        $this->actingAs($user)->post('/finalizacao/pagamento', [
            'payment_method' => 'card',
            'terms_accepted' => true,
        ])->assertStatus(429);
    }
}

<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Tests\TestCase;

class StripeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_intent_creation_is_called_with_a_deterministic_idempotency_key(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createIntent')
                ->once()
                ->withArgs(function ($method, $amount, $metadata, $idempotencyKey) {
                    return $method === 'card'
                        && str_starts_with($idempotencyKey, 'order:')
                        && str_ends_with($idempotencyKey, ':payment:1');
                })
                ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test', 'client_secret' => 'secret_test']));
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

        $this->actingAs($user)->post('/finalizacao/pagamento', [
            'payment_method' => 'card',
            'terms_accepted' => true,
        ])->assertOk();
    }
}

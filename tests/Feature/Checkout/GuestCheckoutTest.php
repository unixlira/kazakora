<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Stripe\PaymentIntent;
use Tests\TestCase;

class GuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function mockStripe(): void
    {
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createIntent')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test_'.uniqid(), 'client_secret' => 'secret_test_'.uniqid()]));
            $mock->shouldReceive('retrieve')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test', 'status' => 'requires_capture']));
            $mock->shouldReceive('capture')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test', 'status' => 'succeeded']));
        });
    }

    public function test_guest_can_checkout_without_logging_in_first(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->mockStripe();

        $product = Product::factory()->create(['price' => 200, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 15, 'is_active' => true]);

        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $this->get('/finalizacao')->assertOk();

        $this->post('/finalizacao/entrega', [
            'shipping_method_id' => $shippingMethod->id,
            'guest' => ['name' => 'Visitante Teste', 'email' => 'visitante@example.com', 'cpf' => '123.456.789-00'],
            'new_address' => [
                'recipient_name' => 'Visitante Teste',
                'phone' => '11988887777',
                'zip' => '01000-000',
                'street' => 'Rua Visitante',
                'number' => '50',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
        ])->assertRedirect(route('finalizacao.pagamento'));

        $this->post('/finalizacao/pagamento', [
            'payment_method' => 'card',
            'terms_accepted' => true,
        ]);

        $user = User::where('email', 'visitante@example.com')->first();
        $this->assertNotNull($user, 'A conta deveria ter sido criada automaticamente.');
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertDatabaseHas('addresses', ['user_id' => $user->id, 'recipient_name' => 'Visitante Teste', 'is_default' => true]);

        $order = Order::where('user_id', $user->id)->first();
        $this->assertNotNull($order);

        $this->get("/finalizacao/{$order->id}/status")->assertOk()->assertJson(['status' => 'paid']);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);
        Queue::assertPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $order->id);
    }

    public function test_guest_checkout_is_rejected_when_email_already_has_an_account(): void
    {
        $existing = User::factory()->create(['email' => 'ja-existe@example.com']);
        $product = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);

        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $response = $this->post('/finalizacao/entrega', [
            'shipping_method_id' => $shippingMethod->id,
            'guest' => ['name' => 'Alguém', 'email' => $existing->email, 'cpf' => '123.456.789-00'],
            'new_address' => [
                'recipient_name' => 'Alguém',
                'phone' => '11988887777',
                'zip' => '01000-000',
                'street' => 'Rua X',
                'number' => '1',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
        ]);

        $response->assertSessionHasErrors('guest.email');
        $this->assertGuest();
    }
}

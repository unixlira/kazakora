<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Stripe\PaymentIntent;
use Tests\TestCase;

class AuthenticatedCheckoutTest extends TestCase
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

    public function test_authenticated_user_can_reuse_a_saved_address(): void
    {
        Mail::fake();
        $this->mockStripe();

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'Casa',
            'recipient_name' => $user->name,
            'phone' => '11999999999',
            'zip' => '01000-000',
            'street' => 'Rua Salva',
            'number' => '10',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'is_default' => true,
        ]);
        $product = Product::factory()->create(['price' => 150, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 20, 'is_active' => true]);

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $delivery = $this->actingAs($user)->get('/finalizacao');
        $delivery->assertInertia(fn ($page) => $page->has('addresses', 1)->where('addresses.0.id', $address->id));

        $this->actingAs($user)->post('/finalizacao/entrega', [
            'shipping_method_id' => $shippingMethod->id,
            'address_id' => $address->id,
        ])->assertRedirect(route('finalizacao.pagamento'));

        $this->actingAs($user)->post('/finalizacao/pagamento', [
            'payment_method' => 'card',
            'terms_accepted' => true,
        ]);

        $order = Order::where('user_id', $user->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('Rua Salva', $order->shipping_street);
        $this->assertEquals(20, $order->shipping_cost);
        $this->assertEquals(170, $order->total);

        $this->assertDatabaseCount('addresses', 1);
    }

    /**
     * BUG REAL 2026-08-13: o campo UF do formulário só ficava maiúsculo NA
     * TELA (CSS puro) — o valor de verdade enviado era o que o usuário
     * digitou. "sp" minúsculo fazia NFeXmlBuilderService comparar errado
     * contra a UF da empresa (===, sensível a caixa) e tratar uma venda
     * dentro do estado como interestadual, CFOP errado na nota fiscal.
     * Cobre também um caminho que não tinha nenhum teste antes: usuário
     * autenticado cadastrando um endereço NOVO durante o checkout (só
     * "reaproveitar endereço salvo" era testado).
     */
    public function test_new_address_created_during_checkout_normalizes_state_to_uppercase(): void
    {
        Mail::fake();
        $this->mockStripe();

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 10, 'is_active' => true]);

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user)->post('/finalizacao/entrega', [
            'shipping_method_id' => $shippingMethod->id,
            'new_address' => [
                'recipient_name' => $user->name,
                'phone' => '11999999999',
                'zip' => '01000-000',
                'street' => 'Rua Nova',
                'number' => '20',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                // minúsculo de propósito — é exatamente o que o bug deixava
                // passar direto pro banco antes da correção.
                'state' => 'sp',
            ],
        ])->assertRedirect(route('finalizacao.pagamento'));

        $this->actingAs($user)->post('/finalizacao/pagamento', [
            'payment_method' => 'card',
            'terms_accepted' => true,
        ]);

        $this->assertDatabaseHas('addresses', ['user_id' => $user->id, 'state' => 'SP']);

        $order = Order::where('user_id', $user->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('SP', $order->shipping_state);
    }
}

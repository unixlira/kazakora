<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29: subtotal/total (e o valor cobrado no Stripe/Mercado
 * Pago) saíam das quantidades do carrinho lidas SEM lock; depois,
 * createOrderItems() — já sob lockForUpdate — fazia min(qtd, estoque) e
 * pulava item com estoque 0. Dois compradores da última unidade (ou uma
 * venda de marketplace no meio do checkout) → o segundo pagava o valor
 * cheio e recebia menos/nenhum item (e a NF-e era rejeitada: soma de vProd
 * != vNF). Agora, se o estoque travado não cobre o carrinho, o pedido
 * inteiro é abortado ANTES de qualquer cobrança e o cliente volta pro
 * carrinho com uma mensagem clara.
 *
 * A "venda concorrente" é simulada no isConfigured() do gateway — ele roda
 * depois de o controller ler o carrinho e antes da transação com lock.
 */
class CheckoutStockRaceTest extends TestCase
{
    use RefreshDatabase;

    private function reachPaymentStep(User $user, array $cart): void
    {
        $shippingMethod = ShippingMethod::factory()->create(['price' => 10, 'is_active' => true]);

        foreach ($cart as $productId => $quantity) {
            $this->actingAs($user)->post('/carrinho', ['product_id' => $productId, 'quantity' => $quantity]);
        }

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
    }

    private function mockStripeWithConcurrentSale(callable $concurrentSale): void
    {
        PaymentGateway::setActive(PaymentGateway::STRIPE);
        $this->mock(StripePaymentService::class, function ($mock) use ($concurrentSale) {
            $mock->shouldReceive('isConfigured')->andReturnUsing(function () use ($concurrentSale) {
                $concurrentSale();

                return true;
            });
            $mock->shouldNotReceive('createIntent');
        });
    }

    public function test_stripe_last_unit_sold_mid_checkout_aborts_without_charging(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['name' => 'Vaso Único', 'price' => 100, 'stock' => 1, 'is_active' => true]);

        $this->reachPaymentStep($user, [$product->id => 1]);
        $this->mockStripeWithConcurrentSale(fn () => Product::whereKey($product->id)->update(['stock' => 0]));

        $response = $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'card', 'terms_accepted' => true]);

        $response->assertRedirect(route('carrinho.ver'));
        $response->assertSessionHasErrors('cart');
        $this->assertStringContainsString('Vaso Único', session('errors')->first('cart'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_stripe_partial_stock_reduction_aborts_whole_order(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $ok = Product::factory()->create(['price' => 50, 'stock' => 10, 'is_active' => true]);
        $short = Product::factory()->create(['name' => 'Luminária', 'price' => 80, 'stock' => 3, 'is_active' => true]);

        $this->reachPaymentStep($user, [$ok->id => 2, $short->id => 2]);
        $this->mockStripeWithConcurrentSale(fn () => Product::whereKey($short->id)->update(['stock' => 1]));

        $response = $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'card', 'terms_accepted' => true]);

        $response->assertRedirect(route('carrinho.ver'));
        $response->assertSessionHasErrors('cart');
        $this->assertStringContainsString('Luminária', session('errors')->first('cart'));
        $this->assertSame(0, Order::count());
        // Nenhum estoque foi baixado (nem do item que tinha estoque sobrando).
        $this->assertSame(10, $ok->fresh()->stock);
        $this->assertSame(1, $short->fresh()->stock);
    }

    public function test_guest_checkout_aborted_by_stock_does_not_leave_account_or_login(): void
    {
        PaymentGateway::setActive(PaymentGateway::STRIPE);
        $product = Product::factory()->create(['price' => 100, 'stock' => 1, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 10, 'is_active' => true]);

        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $this->post('/finalizacao/entrega', [
            'shipping_method_id' => $shippingMethod->id,
            'guest' => ['name' => 'Visitante da Silva', 'email' => 'visitante-race@example.com', 'cpf' => '123.456.789-09'],
            'new_address' => [
                'recipient_name' => 'Visitante',
                'phone' => '11988887777',
                'zip' => '01000-000',
                'street' => 'Rua Visitante',
                'number' => '50',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
        ])->assertRedirect(route('finalizacao.pagamento'));

        $this->mockStripeWithConcurrentSale(fn () => Product::whereKey($product->id)->update(['stock' => 0]));

        $this->post('/finalizacao/pagamento', ['payment_method' => 'card', 'terms_accepted' => true])
            ->assertRedirect(route('carrinho.ver'));

        $this->assertDatabaseMissing('users', ['email' => 'visitante-race@example.com']);
        $this->assertGuest();
        $this->assertSame(0, Order::count());
    }

    public function test_mercadopago_card_aborts_without_creating_mp_order(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cpf' => '123.456.789-09']);
        $product = Product::factory()->create(['price' => 100, 'stock' => 1, 'is_active' => true]);

        $this->reachPaymentStep($user, [$product->id => 1]);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) use ($product) {
            $mock->shouldReceive('isConfigured')->andReturnUsing(function () use ($product) {
                Product::whereKey($product->id)->update(['stock' => 0]);

                return true;
            });
            $mock->shouldNotReceive('createOrder');
            $mock->shouldNotReceive('createPixPayment');
        });

        $this->actingAs($user)->post('/finalizacao/pagamento', [
            'payment_method' => 'card',
            'terms_accepted' => true,
            'mp_card_token' => 'tok_test',
            'mp_card_installments' => 1,
            'mp_payment_method_id' => 'visa',
        ])->assertRedirect(route('carrinho.ver'));

        $this->assertSame(0, Order::count());
    }

    public function test_mercadopago_pix_aborts_without_creating_pix(): void
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cpf' => '123.456.789-09']);
        $product = Product::factory()->create(['price' => 100, 'stock' => 2, 'is_active' => true]);

        $this->reachPaymentStep($user, [$product->id => 2]);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) use ($product) {
            $mock->shouldReceive('isConfigured')->andReturnUsing(function () use ($product) {
                Product::whereKey($product->id)->update(['stock' => 1]);

                return true;
            });
            $mock->shouldNotReceive('createOrder');
            $mock->shouldNotReceive('createPixPayment');
        });

        $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'terms_accepted' => true])
            ->assertRedirect(route('carrinho.ver'));

        $this->assertSame(0, Order::count());
        $this->assertSame(1, $product->fresh()->stock);
    }

    /**
     * Pedido pendente já reservou (baixou) o estoque dele na criação — ao
     * retomá-lo (duplo clique/recarregar), o estoque agora zerado NÃO pode
     * abortar nem criar pedido novo: a unidade é justamente a desse pedido.
     */
    public function test_resuming_pending_order_is_not_blocked_by_its_own_reservation(): void
    {
        PaymentGateway::setActive(PaymentGateway::STRIPE);
        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createIntent')->once()->andReturn(
                PaymentIntent::constructFrom(['id' => 'pi_test_race', 'client_secret' => 'secret_race'])
            );
            $mock->shouldReceive('retrieve')->andReturn(
                PaymentIntent::constructFrom(['id' => 'pi_test_race', 'status' => 'requires_payment_method', 'client_secret' => 'secret_race'])
            );
        });

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 1, 'is_active' => true]);

        $this->reachPaymentStep($user, [$product->id => 1]);

        $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'card', 'terms_accepted' => true])->assertOk();
        $this->assertSame(0, $product->fresh()->stock);

        $this->actingAs($user)->post('/finalizacao/pagamento', ['payment_method' => 'card', 'terms_accepted' => true])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('clientSecret', 'secret_race'));

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Order::first()->items()->count());
    }
}

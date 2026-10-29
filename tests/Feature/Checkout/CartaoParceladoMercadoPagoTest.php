<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cartão parcelado (pedido 2026-10-25): a Order do Mercado Pago vai com as
 * parcelas escolhidas no Brick e o valor em texto com duas casas; parcela
 * fora de 1 a 12 volta para o cliente sem cobrar.
 */
class CartaoParceladoMercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cpf' => '123.456.789-09']);
        $product = Product::factory()->create(['price' => 100.10, 'stock' => 5, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 10, 'is_active' => true]);

        $this->actingAs($this->user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($this->user)->post('/finalizacao/entrega', [
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

    private function cartao(array $extra = []): array
    {
        return [
            'payment_method' => 'card',
            'terms_accepted' => true,
            'mp_card_token' => 'tok_test',
            'mp_card_installments' => 6,
            'mp_payment_method_id' => 'master',
            'mp_issuer_id' => '12518',
            ...$extra,
        ];
    }

    public function test_order_vai_com_as_parcelas_e_valor_com_duas_casas(): void
    {
        $enviado = null;
        $this->mock(MercadoPagoPaymentService::class, function ($mock) use (&$enviado) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createOrder')->once()->andReturnUsing(function (array $payload) use (&$enviado) {
                $enviado = $payload;

                return ['id' => 'ORD1', 'transactions' => ['payments' => [['id' => 'PAY1', 'status' => 'processed', 'status_detail' => 'accredited']]]];
            });
        });

        $this->actingAs($this->user)->post('/finalizacao/pagamento', $this->cartao());

        $this->assertNotNull($enviado);
        $total = number_format((float) Order::firstOrFail()->total, 2, '.', '');
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $enviado['total_amount']);
        $this->assertSame($total, $enviado['total_amount']);
        $this->assertSame($total, $enviado['transactions']['payments'][0]['amount']);
        $this->assertSame([
            'id' => 'master',
            'type' => 'credit_card',
            'token' => 'tok_test',
            'installments' => 6,
        ], $enviado['transactions']['payments'][0]['payment_method']);
    }

    public function test_emissor_numerico_nao_derruba_o_pagamento(): void
    {
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createOrder')->once()->andReturn(['id' => 'ORD2', 'transactions' => ['payments' => [['id' => 'PAY2', 'status' => 'processed', 'status_detail' => 'accredited']]]]);
        });

        $this->actingAs($this->user)->post('/finalizacao/pagamento', $this->cartao(['mp_issuer_id' => 12518]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Order::count());
    }

    public function test_parcela_fora_de_1_a_12_nao_cobra(): void
    {
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldNotReceive('createOrder');
        });

        $this->actingAs($this->user)->post('/finalizacao/pagamento', $this->cartao(['mp_card_installments' => 13]))
            ->assertSessionHasErrors(['mp_card_installments' => 'Escolha em quantas parcelas pagar no cartão.']);

        $this->assertSame(0, Order::count());
    }

    public function test_cartao_a_vista_vai_em_uma_parcela(): void
    {
        $enviado = null;
        $this->mock(MercadoPagoPaymentService::class, function ($mock) use (&$enviado) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createOrder')->once()->andReturnUsing(function (array $payload) use (&$enviado) {
                $enviado = $payload;

                return ['id' => 'ORD3', 'transactions' => ['payments' => [['id' => 'PAY3', 'status' => 'processed', 'status_detail' => 'accredited']]]];
            });
        });

        $this->actingAs($this->user)->post('/finalizacao/pagamento', $this->cartao(['mp_card_installments' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $enviado['transactions']['payments'][0]['payment_method']['installments']);
        $this->assertSame(number_format((float) Order::firstOrFail()->total, 2, '.', ''), $enviado['total_amount']);
    }
}

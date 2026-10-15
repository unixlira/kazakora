<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Auditoria 2026-10-15: `orders:expire-abandoned` cancelava o pedido com Pix
 * sem perguntar ao Mercado Pago. Quem pagava e fechava a aba do checkout
 * ficava com o pedido cancelado e o dinheiro recebido.
 */
class ExpireAbandonedOrdersMercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.access_token' => 'token-de-teste',
            'services.mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'services.mercadopago.modo_teste' => false,
            'services.mercadopago.webhook_secret' => null,
            'mercadopago.retry.times' => 1,
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        Queue::fake();
    }

    /** @return array{0: Order, 1: Payment} */
    private function pedidoComPix(string $metodo = Payment::METHOD_PIX, ?string $mpOrderId = null): array
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 82.60,
            'total' => 82.60,
        ]);
        $order->forceFill(['created_at' => now()->subHour()])->save();

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => $mpOrderId ? null : '7001',
            'mercadopago_order_id' => $mpOrderId,
            'method_type' => $metodo,
            'amount' => 82.60,
            'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
        ]);

        return [$order->fresh(), $payment];
    }

    public function test_pix_pago_com_aba_fechada_confirma_o_pedido_em_vez_de_cancelar(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::response(['id' => 7001, 'status' => 'approved'])]);
        [$order, $payment] = $this->pedidoComPix();

        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_restored_at);
        Queue::assertPushed(\App\Modules\Fiscal\Jobs\GenerateInvoiceJob::class);
    }

    public function test_pix_pendente_e_cancelado_no_mercado_pago_antes_do_pedido(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::sequence()
            ->push(['id' => 7001, 'status' => 'pending'])
            ->push(['id' => 7001, 'status' => 'cancelled'])]);
        [$order, $payment] = $this->pedidoComPix();

        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT' && $request['status'] === 'cancelled');
        $this->assertSame(Payment::STATUS_CANCELED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_pix_pago_no_ultimo_segundo_confirma_mesmo_tentando_cancelar(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::sequence()
            ->push(['id' => 7001, 'status' => 'pending'])
            ->push(['id' => 7001, 'status' => 'approved'])]);
        [$order] = $this->pedidoComPix();

        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_mercado_pago_fora_do_ar_nao_cancela_nesta_rodada(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(['message' => 'erro'], 500)]);
        [$order, $payment] = $this->pedidoComPix();

        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_REQUIRES_CONFIRMATION, $payment->fresh()->status);
    }

    public function test_cartao_processado_no_mercado_pago_confirma_o_pedido(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders/ORD01' => Http::response([
            'id' => 'ORD01',
            'transactions' => ['payments' => [['status' => 'processed']]],
        ])]);
        [$order] = $this->pedidoComPix(Payment::METHOD_CARD, 'ORD01');

        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_webhook_do_topico_payment_confirma_pix_pago(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::response(['id' => 7001, 'status' => 'approved'])]);
        [$order, $payment] = $this->pedidoComPix();

        $this->postJson('/api/mercadopago/webhook?data_id=7001', ['type' => 'payment', 'data' => ['id' => '7001']])
            ->assertOk();

        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_webhook_nao_ressuscita_pedido_cancelado(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::response(['id' => 7001, 'status' => 'approved'])]);
        [$order] = $this->pedidoComPix();
        $order->update(['status' => Order::STATUS_CANCELLED]);

        $this->postJson('/api/mercadopago/webhook?data_id=7001', ['type' => 'payment', 'data' => ['id' => '7001']])
            ->assertOk();

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }
}

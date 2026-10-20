<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderApprovedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Notifications\PagamentoEmPedidoCanceladoNotification;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Achado real 19/10 (#3283): Pix pago às 21:14 com o navegador do cliente
 * em segundo plano só virou pedido pago às 21:40 (varredura de abandonados).
 */
class PixConfirmacaoForaDaTelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.access_token' => 'token-de-teste',
            'services.mercadopago.api_base_url' => 'https://api.mercadopago.com',
            'services.mercadopago.modo_teste' => false,
            'services.mercadopago.webhook_secret' => 'segredo',
            'mercadopago.retry.times' => 1,
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        Queue::fake();
    }

    /** @return array{0: Order, 1: Payment} */
    private function pedidoComPix(string $statusPedido = Order::STATUS_AWAITING_PAYMENT, int $minutos = 5, string $mpId = '7001'): array
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_STORE,
            'status' => $statusPedido,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 157.52,
            'total' => 157.52,
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => $mpId,
            'method_type' => Payment::METHOD_PIX,
            'amount' => 157.52,
            'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
        ]);
        $payment->forceFill(['created_at' => now()->subMinutes($minutos)])->save();

        return [$order->fresh(), $payment->fresh()];
    }

    public function test_varredura_de_um_minuto_confirma_pix_pago_com_a_tela_fechada(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::response(['id' => 7001, 'status' => 'approved'])]);
        [$order, $payment] = $this->pedidoComPix();

        $this->artisan('mercadopago:conferir-pix')->assertSuccessful();

        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        Queue::assertPushed(SendOrderApprovedWhatsAppJob::class);
    }

    public function test_varredura_deixa_pix_ainda_pendente_e_ignora_os_antigos(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::response(['id' => 7001, 'status' => 'pending'])]);
        [$order, $payment] = $this->pedidoComPix();
        [, $antigo] = $this->pedidoComPix(minutos: 180, mpId: '7002');

        $this->artisan('mercadopago:conferir-pix')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(Payment::STATUS_REQUIRES_CONFIRMATION, $payment->fresh()->status);
        $this->assertSame(Payment::STATUS_REQUIRES_CONFIRMATION, $antigo->fresh()->status);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_pix_pago_em_pedido_cancelado_avisa_os_admins_e_nao_reabre(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::response(['id' => 7001, 'status' => 'approved'])]);
        [$order, $payment] = $this->pedidoComPix(Order::STATUS_CANCELLED);

        $this->artisan('mercadopago:conferir-pix')->assertSuccessful();

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);
        Notification::assertSentTo($admin, PagamentoEmPedidoCanceladoNotification::class);
    }

    public function test_aviso_do_topico_payment_confirma_mesmo_sem_assinatura(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::response(['id' => 7001, 'status' => 'approved'])]);
        [$order] = $this->pedidoComPix();

        $this->postJson('/api/mercadopago/webhook?data.id=7001&type=payment', ['type' => 'payment', 'data' => ['id' => '7001']])
            ->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_aviso_de_outro_topico_sem_assinatura_continua_recusado(): void
    {
        $this->postJson('/api/mercadopago/webhook?data.id=ORD1&type=order', ['type' => 'order', 'data' => ['id' => 'ORD1']])
            ->assertStatus(400);
    }

    public function test_pix_e_criado_com_o_endereco_do_aviso(): void
    {
        config(['app.url' => 'https://kazakora.com']);
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 7001, 'status' => 'pending'])]);

        app(MercadoPagoPaymentService::class)->createPixPayment(10.0, ['email' => 'a@b.com'], 'order-1-payment-1', 'chave');

        Http::assertSent(fn (HttpRequest $request) => $request['notification_url'] === 'https://kazakora.com/api/mercadopago/webhook?source_news=webhooks');
    }

    public function test_cancelar_pedido_com_pix_pendente_cancela_o_pix_no_mercado_pago(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/7001' => Http::sequence()
            ->push(['id' => 7001, 'status' => 'pending'])
            ->push(['id' => 7001, 'status' => 'cancelled'])]);
        [$order, $payment] = $this->pedidoComPix();

        $erros = app(OrderPaymentFinalizer::class)->refundOrder($order);

        $this->assertSame([], $erros);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT' && $request['status'] === 'cancelled');
        $this->assertSame(Payment::STATUS_CANCELED, $payment->fresh()->status);
    }

    public function test_cancelar_pedido_com_pix_ja_pago_estorna_em_vez_de_deixar_o_dinheiro(): void
    {
        Http::fake([
            'api.mercadopago.com/v1/payments/7001/refunds' => Http::response(['id' => 1, 'status' => 'approved']),
            'api.mercadopago.com/v1/payments/7001' => Http::response(['id' => 7001, 'status' => 'approved']),
        ]);
        [$order, $payment] = $this->pedidoComPix();

        $erros = app(OrderPaymentFinalizer::class)->refundOrder($order);

        $this->assertSame([], $erros);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/payments/7001/refunds'));
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }
}

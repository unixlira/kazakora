<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\PagamentoRecusado;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\MotivoRecusaMercadoPago;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-10-18: o motivo da recusa do Mercado Pago aparece na
 * tela do pedido no admin, sem abrir o painel do Mercado Pago.
 */
class MotivoRecusaMercadoPagoTest extends TestCase
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

    private function pedido(User $user, array $extra = []): Order
    {
        return Order::create([
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
            'subtotal' => 150,
            'total' => 150,
            ...$extra,
        ]);
    }

    public function test_traduz_codigo_das_duas_apis_e_mostra_desconhecido_cru(): void
    {
        $this->assertSame('Cartão sem limite suficiente', MotivoRecusaMercadoPago::texto('insufficient_amount'));
        $this->assertSame('Cartão sem limite suficiente', MotivoRecusaMercadoPago::texto('cc_rejected_insufficient_amount'));
        $this->assertSame('codigo_novo', MotivoRecusaMercadoPago::texto('codigo_novo'));
        $this->assertNull(MotivoRecusaMercadoPago::texto(null));
    }

    public function test_webhook_de_cartao_recusado_guarda_o_motivo_no_pagamento(): void
    {
        Http::fake(['api.mercadopago.com/v1/orders/ORD9' => Http::response([
            'id' => 'ORD9',
            'transactions' => ['payments' => [['id' => 'PAY9', 'status' => 'failed', 'status_detail' => 'insufficient_amount']]],
        ])]);
        $order = $this->pedido(User::factory()->create(['role' => User::ROLE_CUSTOMER]));
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_order_id' => 'ORD9',
            'method_type' => Payment::METHOD_CARD,
            'amount' => 150,
            'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
        ]);

        $this->postJson('/api/mercadopago/webhook?data_id=ORD9', ['type' => 'order', 'data' => ['id' => 'ORD9']])->assertOk();

        $this->assertSame(Payment::STATUS_CANCELED, $payment->fresh()->status);
        $this->assertSame('insufficient_amount', $payment->fresh()->motivo_recusa);
    }

    public function test_cancelado_sem_recusa_nao_ganha_motivo(): void
    {
        $order = $this->pedido(User::factory()->create(['role' => User::ROLE_CUSTOMER]));
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => '7001',
            'method_type' => Payment::METHOD_PIX,
            'amount' => 150,
            'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
        ]);

        $payment->recusar(['status' => 'cancelled', 'status_detail' => 'expired']);

        $this->assertSame(Payment::STATUS_CANCELED, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->motivo_recusa);
    }

    public function test_tela_do_pedido_mostra_motivo_e_tentativas_recusadas_antes(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'cliente@exemplo.com']);
        $antiga = PagamentoRecusado::create(['email' => 'cliente@exemplo.com', 'metodo' => 'card', 'valor' => 150, 'motivo_recusa' => 'high_risk']);
        $antiga->forceFill(['created_at' => now()->subDays(3)])->save();
        PagamentoRecusado::create(['email' => 'cliente@exemplo.com', 'metodo' => 'card', 'valor' => 150, 'motivo_recusa' => 'high_risk', 'mercadopago_order_id' => 'ORDX']);
        PagamentoRecusado::create(['email' => 'outro@exemplo.com', 'metodo' => 'card', 'valor' => 99, 'motivo_recusa' => 'card_disabled']);
        $this->travel(5)->minutes();

        $order = $this->pedido($cliente);
        Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_order_id' => 'ORDY',
            'method_type' => Payment::METHOD_CARD,
            'amount' => 150,
            'status' => Payment::STATUS_CANCELED,
            'motivo_recusa' => 'cc_rejected_bad_filled_security_code',
        ]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get("/admin/pedidos/{$order->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detalhe.pagamentos.0.motivo_recusa_texto', 'Código de segurança (CVV) errado')
                ->has('detalhe.tentativas_recusadas', 1)
                ->where('detalhe.tentativas_recusadas.0.motivo_recusa_texto', 'Recusado pela prevenção a fraude do Mercado Pago')
                ->where('detalhe.tentativas_recusadas.0.pedido_no_provedor', 'ORDX'));
    }
}

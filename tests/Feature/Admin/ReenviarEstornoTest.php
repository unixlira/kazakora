<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Botão "Reenviar estorno" (2026-10-18, pedido #3219): pedido cancelado
 * com o estorno recusado pelo Mercado Pago seguia cobrado no cartão.
 */
class ReenviarEstornoTest extends TestCase
{
    use RefreshDatabase;

    private function canceladoComCartaoCobrado(string $status = Order::STATUS_CANCELLED): array
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => $status,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '100',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 22.35,
            'total' => 22.35,
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => 'PAY01TESTE'.$order->id,
            'mercadopago_order_id' => 'ORD01TESTE'.$order->id,
            'method_type' => Payment::METHOD_CARD,
            'amount' => 22.35,
            'status' => Payment::STATUS_CAPTURED,
        ]);

        return [$order, $payment];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_tela_mostra_estorno_pendente_do_cancelado_ainda_cobrado(): void
    {
        [$order] = $this->canceladoComCartaoCobrado();

        $this->actingAs($this->admin())
            ->get("/admin/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cancelamento.estorno_pendente.valor', 'R$ 22,35')
                ->where('cancelamento.estorno_pendente.onde', 'Mercado Pago'));
    }

    public function test_reenvia_estorno_com_chave_fixa_e_marca_estornado(): void
    {
        [$order, $payment] = $this->canceladoComCartaoCobrado();
        $this->mock(MercadoPagoPaymentService::class, fn ($mock) => $mock->shouldReceive('refundOrder')
            ->once()->with("ORD01TESTE{$order->id}", null, "estorno-pagamento-{$payment->id}")->andReturn([]));

        $this->actingAs($this->admin())
            ->post("/admin/pedidos/{$order->id}/reenviar-estorno")
            ->assertRedirect()
            ->assertSessionHas('success', 'Estorno de R$ 22,35 feito no Mercado Pago.');

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pedido.reenviar_estorno', 'entity_id' => $order->id]);
        $this->assertNull(app(\App\Modules\Checkout\Support\CancelamentoDoPedido::class)->estornoPendente($order->fresh()));
    }

    public function test_falha_de_novo_mantem_cobrado_e_mostra_o_motivo(): void
    {
        [$order, $payment] = $this->canceladoComCartaoCobrado();
        $this->mock(MercadoPagoPaymentService::class, fn ($mock) => $mock->shouldReceive('refundOrder')
            ->once()->andThrow(new MercadoPagoException('Mercado Pago (HTTP 400, bad_request): saldo insuficiente', 400)));

        $this->actingAs($this->admin())
            ->post("/admin/pedidos/{$order->id}/reenviar-estorno")
            ->assertSessionHas('error', fn (string $erro) => str_contains($erro, 'saldo insuficiente') && str_contains($erro, 'segue cobrado'));

        $this->assertSame(Payment::STATUS_CAPTURED, $payment->fresh()->status);
    }

    public function test_pedido_nao_cancelado_ou_ja_estornado_nao_chama_o_mercado_pago(): void
    {
        [$pago] = $this->canceladoComCartaoCobrado(Order::STATUS_PAID);
        [$estornado, $payment] = $this->canceladoComCartaoCobrado();
        $payment->update(['status' => Payment::STATUS_REFUNDED]);
        $this->mock(MercadoPagoPaymentService::class, fn ($mock) => $mock->shouldNotReceive('refundOrder'));
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/pedidos/{$pago->id}/reenviar-estorno")
            ->assertSessionHas('error', 'Só dá para reenviar o estorno de pedido cancelado.');
        $this->actingAs($admin)->post("/admin/pedidos/{$estornado->id}/reenviar-estorno")
            ->assertSessionHas('error', 'Nada a estornar: nenhum pagamento deste pedido segue cobrado.');
    }

    public function test_erro_da_api_de_orders_vira_mensagem_legivel(): void
    {
        config(['services.mercadopago.api_base_url' => 'https://api.mercadopago.test', 'services.mercadopago.access_token' => 'token-de-teste', 'services.mercadopago.modo_teste' => false, 'mercadopago.retry.times' => 1]);
        Http::fake(['*' => Http::response(['errors' => [['code' => 'idempotency_key_missing', 'message' => 'X-Idempotency-Key header is required']]], 400)]);
        $servico = app(MercadoPagoPaymentService::class);

        try {
            $servico->refundOrder('ORD01TESTE');
            $this->fail('Esperava erro do Mercado Pago.');
        } catch (MercadoPagoException $e) {
            $this->assertSame('Mercado Pago (HTTP 400, idempotency_key_missing): X-Idempotency-Key header is required', $e->getMessage());
        }
    }
}

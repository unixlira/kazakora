<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O pedido do site nasce "aguardando pagamento" no clique do Pix/cartão
 * (reserva de estoque e número para o Mercado Pago). Para a expedição
 * (KoraSync) ele só existe depois de pago (2026-10-17).
 */
class PedidoSoPagoNaExpedicaoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $status, array $attributes = []): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create(array_merge([
            'user_id' => $user->id,
            'status' => $status,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $attributes));
    }

    private function pix(Order $order, string $status): Payment
    {
        return $order->payments()->create([
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_payment_id' => 'mp-'.$order->id,
            'method_type' => Payment::METHOD_PIX,
            'amount' => 100,
            'status' => $status,
        ]);
    }

    private function painel(string $url)
    {
        return $this->withHeaders(['Authorization' => 'Bearer test-print-agent-token'])->getJson('/api/print-agent/dashboard/'.$url);
    }

    public function test_pix_gerado_e_nao_pago_nao_toca_som_de_pedido_novo(): void
    {
        $aguardando = $this->pedido(Order::STATUS_AWAITING_PAYMENT);
        $this->pix($aguardando, Payment::STATUS_REQUIRES_CONFIRMATION);
        $pago = $this->pedido(Order::STATUS_PAID);
        $this->pix($pago, Payment::STATUS_CAPTURED);

        $ids = collect($this->painel('pulse')->assertOk()->json('recent'))->pluck('id')->all();

        $this->assertSame([$pago->id], $ids);
    }

    public function test_pix_pago_depois_de_15_minutos_toca_na_hora_do_pagamento(): void
    {
        $this->travel(-40)->minutes();
        $order = $this->pedido(Order::STATUS_AWAITING_PAYMENT);
        $payment = $this->pix($order, Payment::STATUS_REQUIRES_CONFIRMATION);
        $this->travelBack();

        $this->assertSame([], $this->painel('pulse')->json('recent'));

        $payment->update(['status' => Payment::STATUS_CAPTURED]);
        $order->update(['status' => Order::STATUS_PAID]);

        $this->assertSame([$order->id], collect($this->painel('pulse')->json('recent'))->pluck('id')->all());
    }

    public function test_pix_abandonado_cancelado_nao_aparece_nos_cancelados_do_korasync(): void
    {
        $abandonado = $this->pedido(Order::STATUS_CANCELLED);
        $this->pix($abandonado, Payment::STATUS_CANCELED);
        $estornado = $this->pedido(Order::STATUS_CANCELLED);
        $this->pix($estornado, Payment::STATUS_REFUNDED);
        $marketplace = $this->pedido(Order::STATUS_CANCELLED, ['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP-1']);

        $fila = collect($this->painel('queue')->assertOk()->json('queue'))->pluck('id');

        $this->assertFalse($fila->contains($abandonado->id));
        $this->assertTrue($fila->contains($estornado->id));
        $this->assertTrue($fila->contains($marketplace->id));
        $this->assertSame(2, $this->painel('metrics')->json('cancelled_today'));
    }

    public function test_busca_do_korasync_nao_acha_pedido_sem_pagamento(): void
    {
        $aguardando = $this->pedido(Order::STATUS_AWAITING_PAYMENT, ['shipping_name' => 'Fulana Aguardando']);
        $this->pix($aguardando, Payment::STATUS_REQUIRES_CONFIRMATION);
        $abandonado = $this->pedido(Order::STATUS_CANCELLED, ['shipping_name' => 'Fulana Abandonou']);
        $this->pix($abandonado, Payment::STATUS_CANCELED);
        $pago = $this->pedido(Order::STATUS_PAID, ['shipping_name' => 'Fulana Pagou']);
        $this->pix($pago, Payment::STATUS_CAPTURED);

        $ids = collect($this->painel('pedidos/buscar?q=fulana')->assertOk()->json('orders'))->pluck('id')->all();

        $this->assertSame([$pago->id], $ids);
    }

    public function test_pagamento_confirmado_depois_do_cancelamento_nao_ressuscita_o_pedido(): void
    {
        Queue::fake();
        $order = $this->pedido(Order::STATUS_CANCELLED);
        $order->payments()->create([
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'mercadopago_order_id' => 'ORD-1',
            'method_type' => Payment::METHOD_CARD,
            'amount' => 100,
            'status' => Payment::STATUS_CAPTURED,
        ]);

        $this->assertFalse(app(OrderPaymentFinalizer::class)->finalize($order->fresh()));
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        Queue::assertNothingPushed();
    }
}

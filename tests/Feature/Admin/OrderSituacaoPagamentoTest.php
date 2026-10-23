<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resumo do pagamento na tela do pedido (Lira, 2026-10-20): a equipe vê de
 * relance se pagou, se está aguardando o Pix ou se o Pix foi gerado e não
 * pago e o pedido caiu pelo prazo vencido.
 */
class OrderSituacaoPagamentoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $status, array $extra = []): Order
    {
        return Order::create(array_merge([
            'status' => $status, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000', 'shipping_street' => 'Rua X', 'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 50, 'total' => 50,
        ], $extra));
    }

    private function pix(Order $order, string $status): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'provider' => Payment::PROVIDER_MERCADOPAGO, 'mercadopago_payment_id' => '123',
            'method_type' => Payment::METHOD_PIX, 'amount' => 50, 'status' => $status,
        ]);
    }

    private function situacao(Order $order): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $situacao = null;

        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}")
            ->assertOk()
            ->assertInertia(function ($page) use (&$situacao) {
                $situacao = $page->toArray()['props']['detalhe']['situacao_pagamento'];
            });

        return $situacao;
    }

    public function test_pix_antigo_cancelado_pela_varredura_sem_motivo_aparece_como_expirado(): void
    {
        $order = $this->pedido(Order::STATUS_CANCELLED);
        $this->pix($order, Payment::STATUS_CANCELED);

        $situacao = $this->situacao($order);

        $this->assertSame('pix_expirado', $situacao['codigo']);
        $this->assertSame('error', $situacao['tom']);
    }

    public function test_pix_dentro_do_prazo_aparece_aguardando(): void
    {
        $order = $this->pedido(Order::STATUS_AWAITING_PAYMENT);
        $this->pix($order, Payment::STATUS_REQUIRES_CONFIRMATION);

        $this->assertSame('pix_aguardando', $this->situacao($order)['codigo']);
    }

    public function test_pix_vencido_ainda_nao_cancelado_avisa(): void
    {
        $order = $this->pedido(Order::STATUS_AWAITING_PAYMENT);
        $this->pix($order, Payment::STATUS_REQUIRES_CONFIRMATION)->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->assertSame('pix_vencido', $this->situacao($order)['codigo']);
    }

    /** Pix do Mercado Pago vale 24 h desde 2026-10-23. */
    public function test_pix_de_horas_atras_ainda_esta_no_prazo(): void
    {
        $order = $this->pedido(Order::STATUS_AWAITING_PAYMENT);
        $this->pix($order, Payment::STATUS_REQUIRES_CONFIRMATION)->forceFill(['created_at' => now()->subHours(23)])->save();

        $this->assertSame('pix_aguardando', $this->situacao($order)['codigo']);
    }

    public function test_pix_nao_pago_cancelado_pelo_admin_nao_diz_que_expirou(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->pedido(Order::STATUS_CANCELLED);
        $order->forceFill(['cancelado_por' => $admin->id, 'motivo_cancelamento' => 'Cliente desistiu'])->save();
        $this->pix($order, Payment::STATUS_CANCELED);

        $situacao = $this->situacao($order);

        $this->assertSame('pix_nao_pago_cancelado', $situacao['codigo']);
        $this->assertStringContainsString('Cliente desistiu', $situacao['detalhe']);
    }

    public function test_pix_pago_aparece_pago(): void
    {
        $order = $this->pedido(Order::STATUS_PAID);
        $this->pix($order, Payment::STATUS_CAPTURED);

        $this->assertSame('pago', $this->situacao($order)['codigo']);
    }

    public function test_pedido_de_marketplace_nao_tem_resumo(): void
    {
        $order = $this->pedido(Order::STATUS_PAID, ['origin' => 'shopee', 'external_order_id' => 'X1']);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('detalhe.situacao_pagamento', null));
    }
}

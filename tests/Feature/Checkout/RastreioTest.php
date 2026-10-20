<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Rastrear pedido (pedido 2026-10-10) pelo número ou pelo link assinado
 * /rastreio/{id}-{assinatura}; só com login, do dono ou de admin (2026-10-17).
 */
class RastreioTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(array $extra = []): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'maria@exemplo.com', 'cpf' => '12345678909']);
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));
        $order->items()->create(['product_name' => 'Organizador', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $order;
    }

    public function test_visitante_vai_para_o_login_e_volta_ao_link(): void
    {
        $order = $this->pedido();
        $link = route('rastreio.ver', $order->trackingRef());

        $this->get('/rastreio')->assertRedirect(route('entrar'));
        $this->post('/rastreio', ['pedido' => (string) $order->id])->assertRedirect(route('entrar'));
        $this->get($link)->assertRedirect(route('entrar'));

        $this->post('/entrar', ['email' => 'maria@exemplo.com', 'password' => 'password'])->assertRedirect($link);
    }

    public function test_dono_busca_pelo_numero_e_ve_o_pedido(): void
    {
        $order = $this->pedido();

        $this->actingAs($order->user)->post('/rastreio', ['pedido' => '#'.$order->id])
            ->assertRedirect(route('rastreio.ver', $order->trackingRef()));
        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())->assertOk();
    }

    public function test_outro_cliente_nao_ve_o_pedido(): void
    {
        $order = $this->pedido();
        $outro = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($outro)->get('/rastreio/'.$order->trackingRef())->assertForbidden();
        $this->actingAs($outro)->from('/rastreio')->post('/rastreio', ['pedido' => (string) $order->id])
            ->assertRedirect('/rastreio')
            ->assertSessionHasErrors('pedido');
    }

    public function test_admin_ve_qualquer_pedido(): void
    {
        $order = $this->pedido();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/rastreio/'.$order->trackingRef())->assertOk();
        $this->actingAs($admin)->post('/rastreio', ['pedido' => (string) $order->id])
            ->assertRedirect(route('rastreio.ver', $order->trackingRef()));
    }

    public function test_link_com_assinatura_errada_da_404(): void
    {
        $order = $this->pedido();

        $this->actingAs($order->user)->get('/rastreio/'.$order->id.'-0000000000')->assertNotFound();
        $this->actingAs($order->user)->get('/rastreio/'.$order->id)->assertNotFound();
    }

    public function test_pagina_mostra_etapas_e_codigo_dos_correios(): void
    {
        $order = $this->pedido();
        CorreiosPrePostagem::query()->forceCreate([
            'order_id' => $order->id, 'codigo_objeto' => 'AB123456789BR', 'customer_name' => 'Maria',
            'zip' => '03187040', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Mooca', 'city' => 'São Paulo',
            'state' => 'SP', 'service_code' => '03298', 'weight_grams' => 300, 'content_items' => json_encode([]),
        ]);

        $this->actingAs($order->user)->get('/rastreio/'.$order->trackingRef())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Checkout/Rastreio', false)
                ->where('pedido.id', $order->id)
                ->where('pedido.codigo_rastreio', 'AB123456789BR')
                ->where('pedido.etapas.1.feito', true)
                ->where('pedido.etapas.3.titulo', 'Em preparação')
                ->where('pedido.etapas.3.feito', true)
                ->where('pedido.etapas.4.feito', true)
                ->where('pedido.etapas.5.feito', false)
                ->where('pedido.itens.0.nome', 'Organizador'));
    }

    public function test_meus_pedidos_traz_o_link_de_rastreio(): void
    {
        $order = $this->pedido();

        $this->actingAs($order->user)->get('/pedidos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.tracking_ref', $order->trackingRef()));
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Detalhe do pedido no admin com tudo o que o pedido tem (pedido do Lira
 * 2026-10-16): cliente, endereço, itens, valores, pagamento, nota, envio
 * com o QR Code dos Correios (que saiu da listagem).
 */
class OrderDetalheCompletoTest extends TestCase
{
    use RefreshDatabase;

    public function test_detalhe_traz_cliente_valores_pagamento_e_envio_com_qr(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Maria Compradora', 'shipping_email' => 'maria@exemplo.com', 'shipping_phone' => '11999999999',
            'buyer_document' => '12345678909',
            'shipping_zip' => '13010-000', 'shipping_street' => 'Rua das Flores', 'shipping_number' => '10',
            'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 20, 'coupon_code' => 'BEMVINDO', 'discount_amount' => 15,
            'pix_discount_amount' => 5, 'total' => 105,
        ]);
        Payment::create([
            'order_id' => $order->id, 'provider' => 'mercadopago', 'mercadopago_payment_id' => '998877',
            'method_type' => 'pix', 'amount' => 105, 'status' => Payment::STATUS_CAPTURED,
        ]);
        CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => 'loja',
            'customer_name' => 'Maria Compradora', 'zip' => '13010000', 'street' => 'Rua das Flores', 'number' => '10',
            'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP',
            'service_code' => '03298', 'service_label' => 'PAC (contrato)', 'weight_grams' => 300,
            'dimension_format' => '2', 'content_items' => [], 'status' => CorreiosPrePostagem::STATUS_GERADA,
            'correios_id' => 'PP1', 'codigo_objeto' => 'AP000000001BR', 'qr_payload' => 'AP000000001BR',
        ]);

        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Orders/Show', false)
                ->where('detalhe.cliente.nome', 'Maria Compradora')
                ->where('detalhe.cliente.documento', '12345678909')
                ->where('detalhe.cliente.email', 'maria@exemplo.com')
                ->where('detalhe.endereco.cidade', 'Campinas')
                ->where('detalhe.valores.cupom', 'BEMVINDO')
                ->where('detalhe.valores.desconto_cupom', 10)
                ->where('detalhe.valores.desconto_pix', 5)
                ->where('detalhe.pagamentos.0.transacao', '998877')
                ->where('detalhe.envio.tipo', 'correios_pac')
                ->where('detalhe.envio.rastreio', 'AP000000001BR')
                ->where('detalhe.envio.rastreio_url', 'https://rastreamento.correios.com.br/app/index.php?objetos=AP000000001BR')
                ->where('detalhe.envio.pre_postagens.0.qr_payload', 'AP000000001BR')
                ->where('detalhe.envio.pre_postagens.0.peso_gramas', 300));
    }

    public function test_valores_separam_frete_embutido_e_extra_do_turbo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Ana', 'shipping_email' => 'ana@exemplo.com', 'shipping_phone' => '85999999999',
            'shipping_zip' => '60000-000', 'shipping_street' => 'Rua A', 'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Fortaleza', 'shipping_state' => 'CE',
            'subtotal' => 150, 'frete_embutido_amount' => 30, 'shipping_cost' => 18.5,
            'shipping_carrier_name' => FreteEmbutido::TURBO,
            'discount_amount' => 7.5, 'pix_discount_amount' => 7.5, 'total' => 161,
        ]);

        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detalhe.valores.subtotal', 150)
                ->where('detalhe.valores.frete_embutido', 30)
                ->where('detalhe.valores.turbo', true)
                ->where('detalhe.valores.frete', 18.5)
                ->where('detalhe.valores.desconto_pix', 7.5)
                ->etc());
    }

    public function test_pedido_sem_frete_embutido_guardado_manda_null(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Ana', 'shipping_email' => 'ana@exemplo.com', 'shipping_phone' => '85999999999',
            'shipping_zip' => '60000-000', 'shipping_street' => 'Rua A', 'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Fortaleza', 'shipping_state' => 'CE',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);

        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detalhe.valores.frete_embutido', null)
                ->where('detalhe.valores.turbo', false)
                ->etc());
    }
}

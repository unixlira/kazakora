<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Mail\PixAguardandoPagamento;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layout dos e-mails de pedido refeito para o celular (28/11/2026): itens em
 * linhas com foto, nome, quantidade e preço; resumo com cada desconto numa
 * linha (inclusive o cupom da 2ª unidade) e total que fecha a conta.
 */
class EmailPedidoLayoutCelularTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(): Order
    {
        $travesseiro = Product::factory()->create(['name' => 'Butterfly Comfort Pro™ - Travesseiro Cervical Ortopédico', 'is_active' => true]);
        $travesseiro->images()->create(['path' => 'products/1/travesseiro.jpg', 'is_primary' => true, 'position' => 0]);
        $livro = Product::factory()->create(['name' => 'Aprende Inglês e Português Ouvindo, Cria com Luz e Monta com Ímãs', 'is_active' => true]);

        $order = Order::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CUSTOMER])->id,
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Mariana Aparecida dos Santos',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '04538-133',
            'shipping_street' => 'Avenida Brigadeiro Faria Lima',
            'shipping_number' => '3477',
            'shipping_complement' => 'Apto 142',
            'shipping_neighborhood' => 'Itaim Bibi',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 889.70,
            'shipping_cost' => 0,
            'coupon_code' => 'LEVE22811',
            'discount_amount' => 68.00,
            'pix_discount_amount' => 16.43,
            'desconto_plataforma_amount' => 5.00,
            'total' => 800.27,
        ]);

        $order->items()->create([
            'product_id' => $travesseiro->id, 'product_name' => $travesseiro->name, 'product_price' => 349.90,
            'quantity' => 2, 'subtotal' => 699.80, 'item_type' => OrderItem::TYPE_PRODUCT,
            'leve2_unidades' => 1, 'leve2_preco_normal' => 349.90, 'leve2_preco' => 281.90, 'leve2_desconto' => 68.00,
        ]);
        $order->items()->create([
            'product_id' => $livro->id, 'product_name' => $livro->name, 'product_price' => 189.90,
            'quantity' => 1, 'subtotal' => 189.90, 'item_type' => OrderItem::TYPE_PRODUCT,
        ]);
        $order->payments()->create(['method_type' => Payment::METHOD_PIX, 'status' => 'approved', 'amount' => 800.27]);

        return $order->fresh();
    }

    public function test_confirmacao_mostra_itens_descontos_total_endereco_e_pagamento(): void
    {
        $html = (new OrderConfirmation($this->pedido()))->render();

        // Itens: nome, quantidade, preço da linha e a foto principal.
        $this->assertStringContainsString('Butterfly Comfort Pro™ - Travesseiro Cervical Ortopédico', $html);
        $this->assertStringContainsString('Aprende Inglês e Português Ouvindo, Cria com Luz e Monta com Ímãs', $html);
        $this->assertStringContainsString('Qtd: 2', $html);
        $this->assertStringContainsString('R$ 699,80', $html);
        $this->assertStringContainsString('R$ 189,90', $html);
        $this->assertStringContainsString('storage/products/1/travesseiro.jpg', $html);
        $this->assertStringContainsString('2ª unidade com desconto: - R$ 68,00', $html);

        // Resumo: cada desconto numa linha e o total.
        $this->assertStringContainsString('Subtotal (3 itens)', $html);
        $this->assertStringContainsString('R$ 889,70', $html);
        $this->assertStringContainsString('Cupom LEVE22811 (2ª unidade)', $html);
        $this->assertStringContainsString('- R$ 68,00', $html);
        $this->assertStringContainsString('Desconto no Pix', $html);
        $this->assertStringContainsString('- R$ 16,43', $html);
        $this->assertStringContainsString('Desconto da plataforma', $html);
        $this->assertStringContainsString('- R$ 5,00', $html);
        $this->assertStringContainsString('R$ 800,27', $html);

        // Endereço, pagamento, link do pedido e faixa de andamento.
        $this->assertStringContainsString('Avenida Brigadeiro Faria Lima, 3477', $html);
        $this->assertStringContainsString('CEP 04538-133', $html);
        $this->assertStringContainsString('Pix', $html);
        $this->assertStringContainsString('Acompanhar meu pedido', $html);
        $this->assertStringContainsString('Em preparo', $html);

        // Nada de flex/grid: o Gmail e o Outlook ignoram.
        $this->assertStringNotContainsString('display: flex', $html);
        $this->assertStringNotContainsString('display: grid', $html);
    }

    public function test_email_do_pix_traz_o_resumo_do_pedido(): void
    {
        $order = $this->pedido();

        $html = (new PixAguardandoPagamento($order, 800.27, 'pix-copia-e-cola'))->render();

        $this->assertStringContainsString('Resumo do pedido', $html);
        $this->assertStringContainsString('Butterfly Comfort Pro™ - Travesseiro Cervical Ortopédico', $html);
        $this->assertStringContainsString('pix-copia-e-cola', $html);
        $this->assertStringContainsString('R$ 800,27', $html);
    }
}

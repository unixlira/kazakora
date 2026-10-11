<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Cart\Support\CartManager;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** "Comprar" sem passar pelo carrinho e CPF obrigatório válido (pedido 2026-10-11). */
class CompraDiretaECpfTest extends TestCase
{
    use RefreshDatabase;

    public function test_comprar_leva_so_o_produto_e_nao_mexe_no_carrinho(): void
    {
        $noCarrinho = Product::factory()->create(['price' => 50, 'stock' => 5, 'is_active' => true]);
        $comprado = Product::factory()->create(['price' => 80, 'stock' => 5, 'is_active' => true]);

        $this->post('/carrinho', ['product_id' => $noCarrinho->id, 'quantity' => 1]);
        $this->post('/comprar-agora', ['product_id' => $comprado->id, 'quantity' => 2])->assertRedirect(route('finalizacao.entrega'));

        // A finalização enxerga só a compra direta…
        $this->get('/finalizacao')->assertOk();
        $itens = app(CartManager::class)->items();
        $this->assertSame([$comprado->id], $itens->pluck('product.id')->all());
        $this->assertSame(2, $itens->first()['quantity']);

        // …e o carrinho de verdade continua com o que tinha.
        $this->assertSame([$noCarrinho->id => 1], session('cart'));
        $this->get('/rastreio')->assertInertia(fn (AssertableInertia $page) => $page->where('cart.count', 1));

        // Pedido feito pela compra direta: só ela some.
        app(CartManager::class)->clear();
        $this->assertSame([$noCarrinho->id => 1], session('cart'));
        $this->assertFalse(app(CartManager::class)->emCompraDireta());
    }

    public function test_abrir_o_carrinho_desiste_da_compra_direta(): void
    {
        $produto = Product::factory()->create(['price' => 80, 'stock' => 5, 'is_active' => true]);

        $this->post('/comprar-agora', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->get('/carrinho')->assertOk();

        $this->assertFalse(app(CartManager::class)->emCompraDireta());
    }

    public function test_cliente_logado_com_cpf_invalido_nao_vai_para_o_pagamento(): void
    {
        $user = User::factory()->create(['cpf' => '11111111111']);
        $this->actingAs($user)->withSession(['checkout_draft' => ['shipping_method_id' => 1]])
            ->post('/finalizacao/pagamento', ['payment_method' => 'pix'])
            ->assertRedirect(route('finalizacao.entrega'))
            ->assertSessionHasErrors('cpf');
    }
}

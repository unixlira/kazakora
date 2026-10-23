<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Checkout no modelo Yampi (pedido 2026-10-23): +/− de quantidade no resumo,
 * carrossel só com avaliações reais e pop-up do cupom de 1ª compra só com
 * cupom cadastrado.
 */
class CheckoutYampiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
    }

    private function produto(int $estoque = 5): Product
    {
        return Product::factory()->create(['price' => 100, 'stock' => $estoque, 'is_active' => true]);
    }

    public function test_mais_e_menos_mudam_a_quantidade_sem_tirar_o_item(): void
    {
        $product = $this->produto(3);
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $this->patchJson("/finalizacao/itens/{$product->id}", ['quantity' => 2])->assertOk()->assertJson(['ok' => true, 'quantity' => 2]);
        $this->assertSame([$product->id => 2], session('cart'));

        // Acima do estoque a tela recebe o máximo; zero é recusado.
        $this->patchJson("/finalizacao/itens/{$product->id}", ['quantity' => 9])->assertJson(['quantity' => 3]);
        $this->patchJson("/finalizacao/itens/{$product->id}", ['quantity' => 0])->assertStatus(422);
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('items.0.quantity', 3));
    }

    public function test_quantidade_na_compra_direta_nao_mexe_no_carrinho(): void
    {
        $noCarrinho = $this->produto();
        $comprado = $this->produto();
        $this->post('/carrinho', ['product_id' => $noCarrinho->id, 'quantity' => 1]);
        $this->post('/comprar-agora', ['product_id' => $comprado->id, 'quantity' => 1]);

        $this->patchJson("/finalizacao/itens/{$comprado->id}", ['quantity' => 2])->assertJson(['ok' => true, 'quantity' => 2]);

        $this->assertSame([$noCarrinho->id => 1], session('cart'));
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('items', 1)
            ->where('items.0.product.id', $comprado->id)
            ->where('items.0.quantity', 2));
    }

    public function test_carrossel_so_traz_avaliacoes_reais_com_texto_e_nota_boa(): void
    {
        $product = $this->produto();
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Carla Souza']);
        $outro = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $boa = Review::create(['user_id' => $cliente->id, 'product_id' => $product->id, 'rating' => 5, 'comment' => 'Chegou rápido e funciona muito bem.']);
        Review::create(['user_id' => $outro->id, 'product_id' => $product->id, 'rating' => 2, 'comment' => 'Não gostei do acabamento do produto.']);
        Review::create(['user_id' => $outro->id, 'product_id' => $this->produto()->id, 'rating' => 5, 'comment' => 'Top']);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('avaliacoesCarrossel', 1)
            ->where('avaliacoesCarrossel.0.id', $boa->id)
            ->where('avaliacoesCarrossel.0.nome', 'Carla Souza')
            ->where('avaliacoesCarrossel.0.nota', 5));
    }

    public function test_sem_avaliacao_o_carrossel_fica_vazio(): void
    {
        $product = $this->produto();
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('avaliacoesCarrossel', []));
    }

    public function test_popup_de_cupom_so_aparece_com_cupom_cadastrado_e_valido(): void
    {
        $product = $this->produto();
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        // Sem chave: nada de pop-up.
        config(['loja.cupom_primeira_compra' => null]);
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('cupomPrimeiraCompra', null));

        // Chave com cupom que não existe: nada de pop-up (e nada é criado).
        config(['loja.cupom_primeira_compra' => 'BEMVINDO3']);
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('cupomPrimeiraCompra', null));
        $this->assertSame(0, Coupon::query()->count());

        // Cupom inativo: nada de pop-up.
        $coupon = Coupon::create(['code' => 'BEMVINDO3', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 3, 'is_active' => false]);
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('cupomPrimeiraCompra', null));

        $coupon->update(['is_active' => true]);
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cupomPrimeiraCompra.code', 'BEMVINDO3')
            ->where('cupomPrimeiraCompra.descricao', $coupon->descricaoDesconto()));
    }
}

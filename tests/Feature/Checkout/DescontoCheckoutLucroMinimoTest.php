<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\LucroProduto;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Support\DescontoPix;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Pedido 2026-10-18: "Desconto Checkout" em % ou valor fixo e "Lucro Mínimo"
 * no cadastro. Lucro = preço − Pix − imposto (4,5%) − taxa do Pix (0,99%) −
 * custo; vazio = R$ 10 acima de R$ 30, R$ 8 até R$ 30.
 */
class DescontoCheckoutLucroMinimoTest extends TestCase
{
    use RefreshDatabase;

    private function pagamento(Product $product)
    {
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $metodo = ShippingMethod::factory()->create(['price' => 10, 'is_active' => true]);

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($user)->post('/finalizacao/entrega', [
            'shipping_method_id' => $metodo->id,
            'new_address' => [
                'recipient_name' => 'Cliente', 'phone' => '11999999999', 'zip' => '01000-000',
                'street' => 'Rua A', 'number' => '1', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP',
            ],
        ]);

        return $this->actingAs($user)->get('/finalizacao/pagamento?v=1');
    }

    public function test_valor_fixo_desconta_por_unidade_no_pix(): void
    {
        $product = Product::factory()->create([
            'price' => 100, 'stock' => 5, 'is_active' => true,
            'checkout_discount_percentage' => null, 'checkout_discount_amount' => 10,
        ]);

        $this->pagamento($product)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pixDiscountPercentage', 10)
            ->where('pixDiscountAmount', 20));
    }

    public function test_pix_nunca_desconta_abaixo_do_lucro_minimo(): void
    {
        // Mínimo padrão R$ 10: 100 − (10 + 80) / 0,9451 = 4,77 de desconto no máximo.
        $product = Product::factory()->create([
            'price' => 100, 'cost_price' => 80, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 10,
        ]);

        $this->assertSame(4.77, LucroProduto::descontoPixMaximo($product, 100));
        $this->assertSame(4.77, DescontoPix::percentualDo($product));
        $this->assertGreaterThanOrEqual(10, LucroProduto::lucro($product, 100 - 4.77, 0.99));

        $this->pagamento($product)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pixDiscountAmount', 9.54));
    }

    public function test_lucro_minimo_padrao_e_o_digitado(): void
    {
        $product = new Product(['cost_price' => 10]);

        $this->assertSame(8.0, LucroProduto::lucroMinimo($product, 30));
        $this->assertSame(10.0, LucroProduto::lucroMinimo($product, 30.01));
        $this->assertSame(15.0, LucroProduto::lucroMinimo(new Product(['min_profit' => 15]), 100));
        $this->assertNull(LucroProduto::descontoPixMaximo(new Product(['cost_price' => null]), 100));
    }

    public function test_admin_nao_salva_desconto_checkout_abaixo_do_lucro_minimo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $dados = ['name' => 'Cesto de Bambu', 'category_id' => null, 'price' => 100, 'cost_price' => 80, 'stock' => 3,
            'is_active' => true, 'is_featured' => false, 'is_new_release' => false];

        $this->actingAs($admin)->post('/admin/produtos', [...$dados, 'checkout_discount_percentage' => 10])
            ->assertSessionHasErrors('checkout_discount_percentage');
        $this->actingAs($admin)->post('/admin/produtos', [...$dados, 'checkout_discount_amount' => 5])
            ->assertSessionHasErrors('checkout_discount_amount');
        // Lucro Mínimo menor libera o desconto.
        $this->actingAs($admin)->post('/admin/produtos', [...$dados, 'checkout_discount_amount' => 5, 'checkout_discount_percentage' => null, 'min_profit' => 5])
            ->assertSessionHasNoErrors();

        $product = Product::where('name', 'Cesto de Bambu')->firstOrFail();
        $this->assertEquals(5, (float) $product->checkout_discount_amount);
        $this->assertNull($product->checkout_discount_percentage);
        $this->assertEquals(5, (float) $product->min_profit);
    }

    public function test_pagina_do_produto_nao_mostra_o_lucro_minimo(): void
    {
        $product = Product::factory()->create(['price' => 100, 'cost_price' => 50, 'min_profit' => 12, 'stock' => 5, 'is_active' => true]);

        $this->get("/produtos/{$product->slug}")->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('product.min_profit')
            ->missing('product.cost_price')
            ->etc());
    }
}

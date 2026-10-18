<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Support\DescontoPix;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Pedido 2026-10-18: "Desconto Checkout" em % ou valor fixo no cadastro.
 * Sem trava de Lucro Mínimo (o Lira pediu para tirar): o Pix desconta o que
 * foi cadastrado, mesmo com custo alto.
 */
class DescontoCheckoutSemTravaTest extends TestCase
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

    public function test_pix_desconta_o_cadastrado_mesmo_com_custo_alto(): void
    {
        $product = Product::factory()->create([
            'price' => 100, 'cost_price' => 80, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 10,
        ]);

        $this->assertSame(10.0, DescontoPix::percentualDo($product));

        $this->pagamento($product)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pixDiscountAmount', 20));
    }

    public function test_admin_salva_desconto_checkout_sem_trava_de_lucro(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $dados = ['name' => 'Cesto de Bambu', 'category_id' => null, 'price' => 100, 'cost_price' => 80, 'stock' => 3,
            'is_active' => true, 'is_featured' => false, 'is_new_release' => false];

        $this->actingAs($admin)->post('/admin/produtos', [...$dados, 'checkout_discount_amount' => 15, 'checkout_discount_percentage' => null])
            ->assertSessionHasNoErrors();

        $product = Product::where('name', 'Cesto de Bambu')->firstOrFail();
        $this->assertEquals(15, (float) $product->checkout_discount_amount);
        $this->assertNull($product->checkout_discount_percentage);
    }
}

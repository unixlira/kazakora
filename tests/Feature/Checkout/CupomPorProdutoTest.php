<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cupom só de produtos escolhidos (pedido do Lira 2026-11-11): o
 * BLACKFRIDAY30 dá 30% no massageador e nada nos outros produtos do
 * carrinho; sozinho com outro produto, é recusado.
 */
class CupomPorProdutoTest extends TestCase
{
    use RefreshDatabase;

    private function produto(float $preco): Product
    {
        $p = Product::factory()->create(['price' => $preco, 'stock' => 10, 'is_active' => true, 'discount_percentage' => null, 'discount_amount' => null]);
        FerraKoraProduto::create(['product_id' => $p->id, 'categoria' => 'Teste', 'ordem' => 1, 'ativo' => true]);

        return $p;
    }

    public function test_base_do_cupom_so_com_os_produtos_escolhidos(): void
    {
        $massageador = $this->produto(100);
        $outro = $this->produto(50);
        $variacao = Product::factory()->create(['price' => 100, 'parent_product_id' => $massageador->id]);
        $cupom = Coupon::create(['code' => 'BLACKFRIDAY30', 'name' => 'BF massageador', 'discount_type' => 'percentage', 'discount_value' => 30, 'is_active' => true, 'produtos' => [$massageador->id]]);

        $itens = [
            ['product' => $massageador, 'subtotal' => 100.0],
            ['product' => $outro, 'subtotal' => 50.0],
            ['product' => $variacao, 'subtotal' => 100.0],
        ];

        $this->assertSame(200.0, Coupon::baseDosItens($itens, $cupom));
        $this->assertSame(250.0, Coupon::baseDosItens($itens));
        $this->assertSame(60.0, $cupom->discountFor(Coupon::baseDosItens($itens, $cupom)));

        $cupom->update(['produtos' => null]);
        $this->assertSame(250.0, Coupon::baseDosItens($itens, $cupom->fresh()));
    }

    public function test_checkout_do_site_desconta_so_o_produto_do_cupom_e_recusa_sem_ele(): void
    {
        $massageador = $this->produto(100);
        $outro = $this->produto(50);
        Coupon::create(['code' => 'BLACKFRIDAY30', 'name' => 'BF massageador', 'discount_type' => 'percentage', 'discount_value' => 30, 'is_active' => true, 'produtos' => [$massageador->id]]);

        $this->postJson('/api/ferrakora/checkout/resumo', [
            'itens' => [['variante' => 'kzv-'.$massageador->id, 'quantidade' => 1], ['variante' => 'kzv-'.$outro->id, 'quantidade' => 1]],
            'cupom' => 'BLACKFRIDAY30',
        ])->assertOk()->assertJsonPath('cupom.desconto', 3000);

        $this->postJson('/api/ferrakora/checkout/resumo', [
            'itens' => [['variante' => 'kzv-'.$outro->id, 'quantidade' => 1]],
            'cupom' => 'BLACKFRIDAY30',
        ])->assertOk()->assertJsonPath('cupom', null)->assertJsonPath('cupom_erro', 'Este cupom não vale para os produtos do seu carrinho.');
    }
}

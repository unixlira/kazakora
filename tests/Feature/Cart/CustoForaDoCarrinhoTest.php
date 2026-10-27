<?php

namespace Tests\Feature\Cart;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O custo (cost_price) e o lucro mínimo (min_profit) saíam no JSON do
 * carrinho e do checkout seguro (revisão de produção 2026-10-26). Ficam só
 * no admin.
 */
class CustoForaDoCarrinhoTest extends TestCase
{
    use RefreshDatabase;

    public function test_carrinho_nao_mostra_custo_nem_lucro_minimo(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create(['is_active' => true, 'price' => 420, 'cost_price' => 300, 'stock' => 5]);

        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $this->get('/carrinho')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.product.id', $product->id)
                ->missing('items.0.product.cost_price')
                ->missing('items.0.product.min_profit'))
            ->assertDontSee('cost_price')
            ->assertDontSee('min_profit');
    }
}

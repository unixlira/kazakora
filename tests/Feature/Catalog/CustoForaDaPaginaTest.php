<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O custo do produto (cost_price) saía no JSON da página pública do
 * produto (achado 2026-10-15). Fica só no admin.
 */
class CustoForaDaPaginaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_do_produto_nao_mostra_o_custo(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'price' => 420, 'cost_price' => 300]);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.id', $product->id)->missing('product.cost_price'))
            ->assertDontSee('cost_price');
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Total de itens vendidos saiu da loja (pedido 2026-10-20): nem o card da
 * vitrine nem a página do produto recebem o número.
 */
class SemTotalDeVendidosTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_da_vitrine_nao_traz_vendidos(): void
    {
        Product::factory()->create(['is_active' => true, 'name' => 'Power Bank']);

        $cards = $this->get('/?todos=1')->viewData('page')['props']['products']['data'];

        $this->assertNotEmpty($cards);
        $this->assertArrayNotHasKey('vendidos', $cards[0]);
    }

    public function test_pagina_do_produto_nao_traz_vendidos(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.id', $product->id)->missing('salesCount'));
    }
}

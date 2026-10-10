<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Página de produto v2 (pedido 2026-10-09): a v2 é a padrão e a v1 continua
 * acessível por ?v=1, pra comparar e poder voltar.
 */
class ProductPageVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_page_uses_v2_by_default(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false)->where('product.id', $product->id));
    }

    public function test_v1_is_still_available_with_query_param(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $this->get("/produtos/{$product->slug}?v=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetail', false));

        $this->get("/produtos/{$product->slug}?v=abc")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false));
    }
}

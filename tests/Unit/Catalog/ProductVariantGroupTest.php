<?php

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-17 (variações de produto, estilo Shopee/Mercado
 * Livre): variantGroupIds() é o helper central que substitui todo
 * `where('product_id', $product->id)` que decide "é o mesmo produto" —
 * precisa cobrir os 3 estados possíveis (standalone, pai, filho) e nunca
 * quebrar o caso mais comum hoje (produto sem variação nenhuma).
 */
class ProductVariantGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_product_returns_only_its_own_id(): void
    {
        $product = Product::factory()->create();

        $this->assertSame([$product->id], $product->variantGroupIds());
    }

    public function test_parent_product_returns_itself_and_all_children(): void
    {
        $parent = Product::factory()->create();
        $child1 = Product::factory()->create(['parent_product_id' => $parent->id]);
        $child2 = Product::factory()->create(['parent_product_id' => $parent->id]);
        Product::factory()->create(); // produto solto, não deve aparecer

        $ids = $parent->variantGroupIds();

        $this->assertEqualsCanonicalizing([$parent->id, $child1->id, $child2->id], $ids);
    }

    public function test_child_product_returns_the_parent_and_all_siblings_including_itself(): void
    {
        $parent = Product::factory()->create();
        $child1 = Product::factory()->create(['parent_product_id' => $parent->id]);
        $child2 = Product::factory()->create(['parent_product_id' => $parent->id]);

        $ids = $child1->variantGroupIds();

        $this->assertEqualsCanonicalizing([$parent->id, $child1->id, $child2->id], $ids);
    }
}

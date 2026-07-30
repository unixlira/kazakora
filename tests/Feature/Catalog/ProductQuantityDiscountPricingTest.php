<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductQuantityDiscountPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_price_falls_back_to_final_price_without_tiers(): void
    {
        $product = Product::factory()->create(['price' => 100]);

        $this->assertSame(100.0, $product->unitPriceForQuantity(1));
        $this->assertSame(100.0, $product->unitPriceForQuantity(50));
    }

    public function test_unit_price_applies_the_highest_qualifying_tier(): void
    {
        $product = Product::factory()->create(['price' => 100]);
        $product->quantityDiscounts()->create(['min_quantity' => 5, 'discount_percentage' => 10]);
        $product->quantityDiscounts()->create(['min_quantity' => 10, 'discount_percentage' => 20]);
        $product->load('quantityDiscounts');

        $this->assertSame(100.0, $product->unitPriceForQuantity(4));
        $this->assertSame(90.0, $product->unitPriceForQuantity(5));
        $this->assertSame(90.0, $product->unitPriceForQuantity(9));
        $this->assertSame(80.0, $product->unitPriceForQuantity(10));
        $this->assertSame(80.0, $product->unitPriceForQuantity(999));
    }

    public function test_quantity_discount_percentage_applies_on_top_of_an_existing_promotional_discount(): void
    {
        $product = Product::factory()->create(['price' => 100, 'discount_percentage' => 10]);
        $product->quantityDiscounts()->create(['min_quantity' => 5, 'discount_percentage' => 10]);
        $product->load('quantityDiscounts');

        // final_price já é 90 (10% de promoção); o tier de atacado aplica mais 10% sobre esse valor, não sobre 100.
        $this->assertSame(81.0, $product->unitPriceForQuantity(5));
    }
}

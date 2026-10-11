<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductQuantityDiscountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_replace_a_products_quantity_discount_tiers(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();
        $product->quantityDiscounts()->create(['min_quantity' => 3, 'discount_percentage' => 5]);

        $response = $this->actingAs($admin)->put("/admin/produtos/{$product->id}/descontos-quantidade", [
            'discounts' => [
                ['min_quantity' => 5, 'discount_percentage' => 10],
                ['min_quantity' => 10, 'discount_percentage' => 20],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('product_quantity_discounts', 2);
        $this->assertDatabaseHas('product_quantity_discounts', [
            'product_id' => $product->id,
            'min_quantity' => 5,
            'discount_percentage' => 10,
        ]);
        $this->assertDatabaseMissing('product_quantity_discounts', ['min_quantity' => 3]);
    }

    public function test_duplicate_min_quantities_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();

        $response = $this->actingAs($admin)->put("/admin/produtos/{$product->id}/descontos-quantidade", [
            'discounts' => [
                ['min_quantity' => 5, 'discount_percentage' => 10],
                ['min_quantity' => 5, 'discount_percentage' => 15],
            ],
        ]);

        $response->assertSessionHasErrors('discounts');
        $this->assertDatabaseCount('product_quantity_discounts', 0);
    }

    public function test_guest_cannot_manage_quantity_discounts(): void
    {
        $product = Product::factory()->create();

        $this->put("/admin/produtos/{$product->id}/descontos-quantidade", ['discounts' => []])
            ->assertRedirect('/entrar');
    }
}

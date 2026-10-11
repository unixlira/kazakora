<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSkuPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_a_sku_without_creating_a_product(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $category = Category::factory()->create(['name' => 'Moda']);

        $response = $this->actingAs($admin)->postJson('/admin/produtos/sku-preview', [
            'category_id' => $category->id,
            'name' => 'Camiseta Básica',
            'brand' => 'Nike',
            'model' => 'Dri-Fit',
            'color' => 'Preta',
            'variation' => 'P',
        ]);

        $response->assertOk();
        $response->assertJson(['sku' => 'MOD-CAM-NIK-DRI-PRE-P-0001']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_it_works_with_only_a_name(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->postJson('/admin/produtos/sku-preview', [
            'name' => 'Cadeira Gamer',
        ]);

        $response->assertOk();
        $response->assertJson(['sku' => 'CAD-0001']);
    }

    public function test_a_customer_cannot_call_it(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->postJson('/admin/produtos/sku-preview', ['name' => 'X'])
            ->assertForbidden();
    }
}

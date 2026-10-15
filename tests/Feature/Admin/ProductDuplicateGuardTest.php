<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Trava de produto duplicado no cadastro (pedido do Lira, 2026-10-15). */
class ProductDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['name' => 'Ring Light 10 Polegadas', 'price' => 39.90, 'stock' => 1, 'is_active' => true], $extra);
    }

    public function test_blocks_a_new_product_with_the_same_name_ignoring_case_and_spaces(): void
    {
        Product::factory()->create(['name' => 'Ring Light 10 Polegadas']);

        $this->actingAs($this->admin())
            ->post('/admin/produtos', $this->payload(['name' => '  ring light 10 polegadas ']))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Product::query()->count());
    }

    public function test_allows_the_same_name_as_a_variation_of_that_product(): void
    {
        $parent = Product::factory()->create(['name' => 'Ring Light 10 Polegadas']);

        $this->actingAs($this->admin())
            ->post('/admin/produtos', $this->payload(['parent_product_id' => $parent->id]))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(2, Product::query()->count());
    }

    public function test_allows_a_different_name(): void
    {
        Product::factory()->create(['name' => 'Ring Light 10 Polegadas']);

        $this->actingAs($this->admin())
            ->post('/admin/produtos', $this->payload(['name' => 'Tripé Articulado']))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(2, Product::query()->count());
    }
}

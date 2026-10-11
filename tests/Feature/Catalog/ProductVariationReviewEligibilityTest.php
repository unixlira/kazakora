<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-17 (variações de produto): comprou a variação
 * "10 Polegadas" tem que poder avaliar a variação "8 Polegadas" da mesma
 * página — mesmo item físico. Antes disso a checagem de "já comprou" era um
 * where('product_id', $product->id) exato, cego a variação nenhuma.
 */
class ProductVariationReviewEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedOrder(User $user, Product $product): void
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_COMPLETED,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);
    }

    public function test_buying_one_variation_allows_reviewing_a_sibling_variation(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $parent = Product::factory()->create(['name' => 'Ring Light 10 Polegadas', 'is_active' => true]);
        $sibling = Product::factory()->create(['name' => 'Ring Light 8 Polegadas', 'is_active' => true, 'parent_product_id' => $parent->id]);

        $this->makeCompletedOrder($user, $parent);

        $response = $this->actingAs($user)->get("/produtos/{$sibling->slug}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('canReview', true));
    }

    public function test_buying_an_unrelated_product_does_not_allow_reviewing_this_one(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $unrelated = Product::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['is_active' => true]);

        $this->makeCompletedOrder($user, $unrelated);

        $response = $this->actingAs($user)->get("/produtos/{$product->slug}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('canReview', false));
    }

    public function test_standalone_product_review_eligibility_is_unaffected_by_the_variation_feature(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['is_active' => true]);

        $this->makeCompletedOrder($user, $product);

        $response = $this->actingAs($user)->get("/produtos/{$product->slug}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('canReview', true));
    }
}

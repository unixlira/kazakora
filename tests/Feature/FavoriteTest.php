<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_when_favoriting(): void
    {
        $product = Product::factory()->create();

        $this->post("/favoritos/{$product->id}")->assertRedirect('/entrar');
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_authenticated_user_can_toggle_a_favorite(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create();

        $this->actingAs($user)->post("/favoritos/{$product->id}")->assertRedirect();
        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'product_id' => $product->id]);

        $this->actingAs($user)->post("/favoritos/{$product->id}")->assertRedirect();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_homepage_shares_the_current_users_favorite_ids(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['is_active' => true]);

        $this->actingAs($user)->post("/favoritos/{$product->id}");

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn ($page) => $page->where('favoriteIds', [$product->id]));
    }
}

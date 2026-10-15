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

    public function test_guest_sees_the_favorites_kept_in_the_browser(): void
    {
        $guardado = Product::factory()->create(['is_active' => true]);
        $inativo = Product::factory()->create(['is_active' => false]);
        Product::factory()->create(['is_active' => true]);

        $this->get("/favoritos?ids={$guardado->id},{$inativo->id},abc")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visitante', true)
                ->has('products.data', 1)
                ->where('products.data.0.id', $guardado->id));
    }

    public function test_login_merges_browser_favorites_into_the_account(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $naConta = Product::factory()->create();
        $doNavegador = Product::factory()->create();

        $this->actingAs($user)->post("/favoritos/{$naConta->id}");

        $this->actingAs($user)
            ->postJson('/favoritos/sincronizar', ['produtos' => [$doNavegador->id, $naConta->id, 999999]])
            ->assertOk()
            ->assertJsonCount(2, 'ids');

        $this->assertDatabaseCount('favorites', 2);
    }

    public function test_toggle_answers_json_for_the_store_button(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create();

        $this->actingAs($user)->postJson("/favoritos/{$product->id}")
            ->assertOk()->assertJson(['favorito' => true, 'ids' => [$product->id]]);

        $this->actingAs($user)->postJson("/favoritos/{$product->id}")
            ->assertOk()->assertJson(['favorito' => false, 'ids' => []]);
    }

    public function test_favorites_page_only_lists_the_users_own_favorited_products(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $favorited = Product::factory()->create(['is_active' => true]);
        $notFavorited = Product::factory()->create(['is_active' => true]);

        $this->actingAs($user)->post("/favoritos/{$favorited->id}");
        $this->actingAs($other)->post("/favoritos/{$notFavorited->id}");

        $response = $this->actingAs($user)->get('/favoritos');

        $response->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $favorited->id));
    }
}

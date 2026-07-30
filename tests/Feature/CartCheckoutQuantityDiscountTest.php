<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\OrderConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CartCheckoutQuantityDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_total_reflects_the_applicable_quantity_discount_tier(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 50, 'is_active' => true]);
        $product->quantityDiscounts()->create(['min_quantity' => 5, 'discount_percentage' => 10]);

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 5])
            ->assertRedirect();

        $response = $this->actingAs($user)->get('/carrinho');

        // 5 unidades a R$90 (10% OFF a partir de 5) = R$450, não R$500.
        $response->assertInertia(fn ($page) => $page
            ->where('total', 450)
            ->where('items.0.subtotal', 450));
    }

    public function test_checkout_charges_the_quantity_discount_price_on_the_order_item(): void
    {
        Mail::fake();

        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 100, 'stock' => 50, 'is_active' => true]);
        $product->quantityDiscounts()->create(['min_quantity' => 5, 'discount_percentage' => 10]);

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 5]);

        $this->actingAs($user)->post('/finalizacao', [
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '100',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
        ])->assertRedirect();

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'quantity' => 5,
            'product_price' => 90,
            'subtotal' => 450,
        ]);

        Mail::assertQueued(OrderConfirmation::class);
    }
}

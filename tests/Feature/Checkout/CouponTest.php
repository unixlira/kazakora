<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Operacional\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    private function reachPaymentStep(User $user, Product $product, ShippingMethod $shippingMethod): void
    {
        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user)->post('/finalizacao/entrega', [
            'shipping_method_id' => $shippingMethod->id,
            'new_address' => [
                'recipient_name' => 'Cliente Teste',
                'phone' => '11999999999',
                'zip' => '01000-000',
                'street' => 'Rua Teste',
                'number' => '100',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
        ]);
    }

    public function test_valid_coupon_discounts_the_total(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 200, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        Coupon::create(['code' => 'DEZOFF', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 10, 'is_active' => true]);

        $this->reachPaymentStep($user, $product, $shippingMethod);

        $this->actingAs($user)->post('/finalizacao/pagamento/cupom', ['code' => 'DEZOFF'])->assertRedirect();

        $response = $this->actingAs($user)->get('/finalizacao/pagamento');

        $response->assertInertia(fn ($page) => $page
            ->where('couponCode', 'DEZOFF')
            ->where('discountAmount', 20)
            ->where('total', 180));
    }

    public function test_invalid_coupon_is_rejected(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 200, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);

        $this->reachPaymentStep($user, $product, $shippingMethod);

        $response = $this->actingAs($user)->post('/finalizacao/pagamento/cupom', ['code' => 'NAOEXISTE']);

        $response->assertSessionHasErrors('code');
    }

    public function test_inactive_coupon_is_rejected(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $product = Product::factory()->create(['price' => 200, 'stock' => 10, 'is_active' => true]);
        $shippingMethod = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        Coupon::create(['code' => 'EXPIRADO', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 50, 'is_active' => false]);

        $this->reachPaymentStep($user, $product, $shippingMethod);

        $response = $this->actingAs($user)->post('/finalizacao/pagamento/cupom', ['code' => 'EXPIRADO']);

        $response->assertSessionHasErrors('code');
    }
}

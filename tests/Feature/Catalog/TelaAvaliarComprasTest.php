<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Esboço "Avalie suas compras" (pedido 2026-10-20): só produto entregue, com a avaliação já feita. */
class TelaAvaliarComprasTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(User $user, Product $product, string $status): void
    {
        $order = Order::create([
            'user_id' => $user->id, 'status' => $status, 'origin' => Order::ORIGIN_STORE, 'shipping_name' => 'X', 'shipping_phone' => '1', 'shipping_zip' => '1',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'C', 'shipping_state' => 'SP',
            'subtotal' => 10, 'shipping_cost' => 0, 'total' => 10,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10, 'item_type' => OrderItem::TYPE_PRODUCT]);
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/avaliar')->assertRedirect();
    }

    public function test_lista_so_entregues_e_traz_avaliacao_existente(): void
    {
        $user = User::factory()->create();
        $entregue = Product::factory()->create(['is_active' => true, 'name' => 'Trena']);
        $avaliado = Product::factory()->create(['is_active' => true, 'name' => 'Lixeira']);
        $aCaminho = Product::factory()->create(['is_active' => true, 'name' => 'Power Bank']);
        $this->pedido($user, $entregue, Order::STATUS_COMPLETED);
        $this->pedido($user, $avaliado, Order::STATUS_COMPLETED);
        $this->pedido($user, $aCaminho, Order::STATUS_SHIPPED);
        Review::create(['user_id' => $user->id, 'product_id' => $avaliado->id, 'rating' => 4, 'comment' => 'Bom']);

        $this->actingAs($user)->get('/avaliar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/Avaliar', false)->has('produtos', 2)
                ->where('produtos', fn ($produtos) => collect($produtos)->pluck('id')->sort()->values()->all() === collect([$entregue->id, $avaliado->id])->sort()->values()->all()
                    && collect($produtos)->firstWhere('id', $avaliado->id)['avaliacao']['nota'] === 4
                    && collect($produtos)->firstWhere('id', $entregue->id)['avaliacao'] === null));
    }
}

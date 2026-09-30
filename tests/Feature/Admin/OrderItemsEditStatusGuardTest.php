<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29 (revisão de código): PATCH pedidos/{order}/itens
 * (OrderController::updateItems) não olhava o status do pedido — editar
 * itens de um pedido cancelado (estoque já devolvido) ou que já nasceu
 * cancelado (nunca debitou) mexia no estoque de novo, errado.
 */
class OrderItemsEditStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: Product, 2: \App\Modules\Checkout\Models\OrderItem} */
    private function orderWithDebitedItem(array $overrides = []): array
    {
        $product = Product::factory()->create(['stock' => 10]);

        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '100',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 20,
            'total' => 20,
        ], $overrides));

        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 10,
            'quantity' => 2,
            'subtotal' => 20,
        ]);

        app(StockManager::class)->adjust($product, -2, StockMovement::TYPE_SALE, reference: $order);

        return [$order, $product, $item];
    }

    public function test_paid_order_items_can_still_be_edited(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$order, $product, $item] = $this->orderWithDebitedItem();

        $this->actingAs($admin)
            ->patch("/admin/pedidos/{$order->id}/itens", ['items' => [['id' => $item->id, 'product_id' => $product->id, 'quantity' => 3]]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(3, $item->fresh()->quantity);
    }

    public function test_cancelled_order_with_restored_stock_cannot_have_items_edited(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$order, $product, $item] = $this->orderWithDebitedItem();
        $order->update(['status' => Order::STATUS_CANCELLED]);
        app(\App\Modules\Checkout\Support\OrderPaymentFinalizer::class)->restoreStockIfNeeded($order, 'Cancelado');
        $this->assertSame(10, $product->fresh()->stock);

        $this->actingAs($admin)
            ->patch("/admin/pedidos/{$order->id}/itens", ['items' => [['id' => $item->id, 'product_id' => $product->id, 'quantity' => 1]]])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(10, $product->fresh()->stock, 'Editar pedido cancelado não pode mexer no estoque.');
        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_paid_order_with_stock_already_restored_cannot_have_items_edited(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$order, $product, $item] = $this->orderWithDebitedItem(['stock_restored_at' => now()]);

        $this->actingAs($admin)
            ->patch("/admin/pedidos/{$order->id}/itens", ['items' => [['id' => $item->id, 'product_id' => $product->id, 'quantity' => 5]]])
            ->assertSessionHas('error');

        $this->assertSame(8, $product->fresh()->stock);
    }
}

<?php

namespace Tests\Feature\GestaoOperacional;

use App\Models\User;
use App\Modules\Cadastros\Models\Supplier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Operacional\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_and_receive_a_purchase_order_which_restocks_products(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $supplier = Supplier::create(['name' => 'Fornecedor Teste']);
        $product = Product::factory()->create(['stock' => 10]);

        $response = $this->actingAs($manager)->post('/admin/pedidos-de-compra', [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 20.5],
            ],
        ]);

        $response->assertRedirect();
        $purchaseOrder = PurchaseOrder::query()->firstOrFail();
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $purchaseOrder->status);
        $this->assertCount(1, $purchaseOrder->items);

        $this->actingAs($manager)->post("/admin/pedidos-de-compra/{$purchaseOrder->id}/receber")->assertRedirect();

        $this->assertSame(15, $product->fresh()->stock);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $purchaseOrder->fresh()->status);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_RESTOCK,
            'quantity' => 5,
        ]);
    }

    public function test_subscriber_can_view_but_not_create_purchase_orders(): void
    {
        $subscriber = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);

        $this->actingAs($subscriber)->get('/admin/pedidos-de-compra')->assertOk();
        $this->actingAs($subscriber)->get('/admin/pedidos-de-compra/criar')->assertForbidden();
    }

    public function test_received_purchase_orders_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $supplier = Supplier::create(['name' => 'Fornecedor']);
        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)->delete("/admin/pedidos-de-compra/{$purchaseOrder->id}")->assertRedirect();

        $this->assertDatabaseHas('purchase_orders', ['id' => $purchaseOrder->id]);
    }
}

<?php

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29 (revisão de código): estoque fantasma depois de
 * venda acima do estoque + cancelamento. StockManager::adjust() clampa em
 * 0 mas gravava o delta cheio no movimento, e restoreStockIfNeeded()
 * devolvia a quantidade cheia do item. Estoque 1, vende 3 → 0; cancela →
 * +3 → estoque 3 com 1 unidade física.
 */
class StockRestoreAfterOversellTest extends TestCase
{
    use RefreshDatabase;

    private function createOrderWithItem(Product $product, int $quantity): Order
    {
        $order = Order::create([
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
            'subtotal' => 100,
            'total' => 100,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 10,
            'quantity' => $quantity,
            'subtotal' => 10 * $quantity,
        ]);

        return $order;
    }

    public function test_clamped_adjust_records_only_the_effective_delta(): void
    {
        $product = Product::factory()->create(['stock' => 1]);

        $movement = app(StockManager::class)->adjust($product, -3, StockMovement::TYPE_SALE);

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(-1, $movement->quantity, 'Só 1 unidade saiu de verdade do estoque.');
    }

    public function test_cancel_after_oversell_restores_only_what_was_debited(): void
    {
        $product = Product::factory()->create(['stock' => 1]);
        $order = $this->createOrderWithItem($product, 3);

        app(StockManager::class)->adjust($product, -3, StockMovement::TYPE_SALE, reference: $order);
        $this->assertSame(0, $product->fresh()->stock);

        app(OrderPaymentFinalizer::class)->restoreStockIfNeeded($order, 'Pedido cancelado no canal de origem');

        $this->assertSame(1, $product->fresh()->stock, 'Devolveu mais do que tinha sido debitado — estoque fantasma.');
    }

    public function test_normal_cancel_still_restores_the_full_quantity(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $order = $this->createOrderWithItem($product, 3);

        app(StockManager::class)->adjust($product, -3, StockMovement::TYPE_SALE, reference: $order);
        app(OrderPaymentFinalizer::class)->restoreStockIfNeeded($order, 'Cancelado');

        $this->assertSame(10, $product->fresh()->stock);
    }

    /**
     * Dois cancelamentos concorrentes (webhook + varredura) com o pedido
     * carregado em memória antes de qualquer um gravar stock_restored_at.
     */
    public function test_concurrent_restores_only_return_stock_once(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $order = $this->createOrderWithItem($product, 2);
        app(StockManager::class)->adjust($product, -2, StockMovement::TYPE_SALE, reference: $order);

        $copiaA = Order::find($order->id);
        $copiaB = Order::find($order->id);

        app(OrderPaymentFinalizer::class)->restoreStockIfNeeded($copiaA, 'Webhook');
        app(OrderPaymentFinalizer::class)->restoreStockIfNeeded($copiaB, 'Varredura');

        $this->assertSame(10, $product->fresh()->stock, 'Estoque devolvido duas vezes.');
        $this->assertNotNull($copiaB->stock_restored_at);
    }
}

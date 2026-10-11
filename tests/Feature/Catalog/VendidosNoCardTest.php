<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Vendidos no card (pedido 2026-10-10, como no Mercado Livre): pagos/enviados/entregues, somando variações. */
class VendidosNoCardTest extends TestCase
{
    use RefreshDatabase;

    private function venda(Product $product, int $quantidade, string $status): void
    {
        $order = Order::create([
            'status' => $status, 'origin' => Order::ORIGIN_STORE, 'shipping_name' => 'X', 'shipping_phone' => '1', 'shipping_zip' => '1',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'C', 'shipping_state' => 'SP',
            'subtotal' => 10, 'shipping_cost' => 0, 'total' => 10,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'P', 'product_price' => 10, 'quantity' => $quantidade, 'subtotal' => 10, 'item_type' => OrderItem::TYPE_PRODUCT]);
    }

    public function test_card_traz_vendidos_somando_variacoes_e_ignorando_nao_pagos(): void
    {
        $pai = Product::factory()->create(['is_active' => true, 'name' => 'Power Bank']);
        $variacao = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id]);
        $semVenda = Product::factory()->create(['is_active' => true, 'name' => 'Trena']);
        $this->venda($pai, 3, Order::STATUS_PAID);
        $this->venda($variacao, 4, Order::STATUS_COMPLETED);
        $this->venda($pai, 50, Order::STATUS_CANCELLED);

        $vendidos = collect($this->get('/?todos=1')->viewData('page')['props']['products']['data'])->pluck('vendidos', 'id');

        $this->assertSame(7, (int) $vendidos[$pai->id]);
        $this->assertSame(0, (int) $vendidos[$semVenda->id]);
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Total de itens vendidos saiu do card da vitrine (pedido 2026-10-20). Na
 * página do produto ele voltou com o número real (pedido 2026-10-23): só
 * venda efetiva paga, enviada ou entregue, somando as variações. A página só
 * mostra a partir de 50.
 */
class SemTotalDeVendidosTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_da_vitrine_nao_traz_vendidos(): void
    {
        Product::factory()->create(['is_active' => true, 'name' => 'Power Bank']);

        $cards = $this->get('/?todos=1')->viewData('page')['props']['products']['data'];

        $this->assertNotEmpty($cards);
        $this->assertArrayNotHasKey('vendidos', $cards[0]);
    }

    public function test_pagina_do_produto_soma_so_vendas_efetivas_com_variacoes(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $variacao = Product::factory()->create(['is_active' => true, 'parent_product_id' => $product->id]);

        $this->pedido($product, 40, Order::STATUS_COMPLETED);
        $this->pedido($variacao, 15, Order::STATUS_SHIPPED);
        $this->pedido($product, 100, Order::STATUS_CANCELLED);
        $this->pedido($product, 200, Order::STATUS_PAID, ['estornado_em' => now()]);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.id', $product->id)->where('salesCount', 55));
    }

    private function pedido(Product $product, int $quantidade, string $status, array $extra = []): void
    {
        $order = Order::create(array_merge([
            'status' => $status,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_name' => 'Ana Rosa',
            'shipping_phone' => '21999999999',
            'shipping_zip' => '24934105',
            'shipping_street' => 'Rua X',
            'shipping_number' => '39',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Maricá',
            'shipping_state' => 'RJ',
            'subtotal' => 10,
            'shipping_cost' => 0,
            'total' => 10,
        ]));
        // estornado_em não é preenchível em massa.
        $order->forceFill($extra)->save();

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 10,
            'quantity' => $quantidade,
            'subtotal' => 10 * $quantidade,
            'item_type' => OrderItem::TYPE_PRODUCT,
        ]);
    }
}

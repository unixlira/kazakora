<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Relato 2026-10-07 ("está sem imagem"): o card de Vendas futuras do
 * KoraSync busca a foto de cada item por product_id, e a lista de
 * agendadas só mandava nome e quantidade.
 */
class ScheduledShipmentsProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_shipments_send_product_id_and_sku_of_each_item(): void
    {
        $produto = Product::factory()->create(['sku' => 'BRQ-MAG-VEST35-RS']);
        $pedido = Order::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CUSTOMER])->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-SCHED-IMG',
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);
        $item = $pedido->items()->create(['product_id' => $produto->id, 'product_name' => 'Quebra-cabeça', 'product_price' => 50, 'quantity' => 1, 'subtotal' => 50]);
        $avulso = $pedido->items()->create(['product_name' => 'Sem vínculo', 'product_price' => 50, 'quantity' => 1, 'subtotal' => 50]);
        ChannelShipment::create([
            'order_id' => $pedido->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'shipping_method' => 'xd_drop_off',
            'confirmed_at' => now(),
            'scheduled_for' => now()->addDays(6),
        ]);

        $produtos = collect($this->withHeaders(['Authorization' => 'Bearer test-print-agent-token'])
            ->getJson('/api/print-agent/dashboard/scheduled-shipments')
            ->assertOk()
            ->json('scheduled_shipments.0.products'))->keyBy('id');

        $this->assertSame($produto->id, $produtos[$item->id]['product_id']);
        $this->assertSame('BRQ-MAG-VEST35-RS', $produtos[$item->id]['sku']);
        $this->assertNull($produtos[$avulso->id]['product_id']);
        $this->assertNull($produtos[$avulso->id]['sku']);
    }
}

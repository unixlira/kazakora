<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $attributes = []): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create(array_merge([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
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
        ], $attributes));
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-print-agent-token'];
    }

    public function test_dashboard_endpoints_reject_requests_without_a_valid_token(): void
    {
        $this->getJson('/api/print-agent/dashboard/channels')->assertStatus(401);
        $this->getJson('/api/print-agent/dashboard/metrics')->assertStatus(401);
    }

    public function test_channels_reports_connection_status_and_last_order_per_channel(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123',
        ]);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_DISCONNECTED,
        ]);

        $storeOrder = $this->makeOrder(['origin' => Order::ORIGIN_STORE]);
        $mlOrder = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-1']);

        $printJob = PrintJob::create([
            'order_id' => $mlOrder->id,
            'label_path' => 'labels/ml-1.pdf',
            'status' => PrintJob::STATUS_PRINTED,
            'printed_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/channels');

        $response->assertOk();
        $channels = collect($response->json('channels'))->keyBy('channel');

        $this->assertTrue($channels[Order::ORIGIN_STORE]['connected']);
        $this->assertSame($storeOrder->id, $channels[Order::ORIGIN_STORE]['last_order']['id']);

        $this->assertTrue($channels[Order::ORIGIN_MERCADO_LIVRE]['connected']);
        $this->assertSame($mlOrder->id, $channels[Order::ORIGIN_MERCADO_LIVRE]['last_order']['id']);
        $this->assertSame(1, $channels[Order::ORIGIN_MERCADO_LIVRE]['labels_printed_today']);
        $this->assertNotNull($channels[Order::ORIGIN_MERCADO_LIVRE]['last_label_printed_at']);

        $this->assertFalse($channels[Order::ORIGIN_SHOPEE]['connected']);
        $this->assertNull($channels[Order::ORIGIN_SHOPEE]['last_order']);

        $this->assertFalse($channels[Order::ORIGIN_TIKTOK_SHOP]['connected']);
    }

    public function test_metrics_reports_todays_revenue_sales_cancellations_refunds_and_cart_items(): void
    {
        $mlOrder = $this->makeOrder(['status' => Order::STATUS_PAID, 'total' => 150, 'origin' => Order::ORIGIN_MERCADO_LIVRE]);
        OrderChannelFee::create([
            'order_id' => $mlOrder->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'gross_amount' => 150,
            'fee_amount' => 20,
            'source' => OrderChannelFee::SOURCE_API,
            'computed_at' => now(),
        ]);
        $this->makeOrder(['status' => Order::STATUS_COMPLETED, 'total' => 50]);
        $this->makeOrder(['status' => Order::STATUS_CANCELLED, 'total' => 30]);
        $this->makeOrder(['status' => Order::STATUS_AWAITING_PAYMENT, 'total' => 999]);

        $refundedOrder = $this->makeOrder(['status' => Order::STATUS_PAID, 'total' => 80]);
        Payment::create([
            'order_id' => $refundedOrder->id,
            'provider' => Payment::PROVIDER_MERCADOPAGO,
            'method_type' => Payment::METHOD_CARD,
            'status' => Payment::STATUS_REFUNDED,
            'amount' => 80,
        ]);

        CartSnapshot::create(['session_id' => 'sess-1', 'items_count' => 3, 'total' => 200]);
        CartSnapshot::create(['session_id' => 'sess-2', 'items_count' => 2, 'total' => 90]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/metrics');

        $response->assertOk();
        $response->assertJson([
            'revenue_today' => 280.0,
            'sales_today' => 3,
            'cancelled_today' => 1,
            'refunded_today' => 1,
            'cart_items_count' => 5,
            // 280 de bruto - 20 de taxa real (só o pedido do ML tem taxa
            // capturada) - os outros dois pedidos pagos hoje não têm
            // OrderChannelFee, então entram sem desconto nenhum.
            'net_profit_today' => 260.0,
        ]);
    }

    public function test_channel_orders_returns_404_for_an_unknown_channel(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/channels/nao-existe/orders')
            ->assertNotFound();
    }

    public function test_channel_orders_reports_products_fee_and_shipping_method_when_available(): void
    {
        $order = $this->makeOrder([
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-123',
            'shipping_name' => 'Fulano de Tal',
            'total' => 180.80,
        ]);

        $order->items()->create([
            'product_name' => 'Lixeira Inox 12l',
            'product_price' => 180.80,
            'quantity' => 1,
            'subtotal' => 180.80,
        ]);

        OrderChannelFee::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'gross_amount' => 180.80,
            'fee_amount' => 27.12,
            'source' => OrderChannelFee::SOURCE_API,
            'computed_at' => now(),
        ]);

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_method' => ChannelShipment::METHOD_FLEX,
        ]);

        // Pedido de outro canal não deve aparecer na lista do Mercado Livre.
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/channels/mercado_livre/orders');

        $response->assertOk();
        $orders = $response->json('orders');

        $this->assertCount(1, $orders);
        $this->assertSame($order->id, $orders[0]['id']);
        $this->assertSame('ML-123', $orders[0]['external_order_id']);
        $this->assertSame('Fulano de Tal', $orders[0]['customer_name']);
        $this->assertSame('Lixeira Inox 12l', $orders[0]['products'][0]['name']);
        $this->assertSame(180.80, $orders[0]['gross_amount']);
        $this->assertSame(27.12, $orders[0]['fee_amount']);
        $this->assertSame(153.68, $orders[0]['net_amount']);
        $this->assertSame(ChannelShipment::METHOD_FLEX, $orders[0]['shipping_method']);
    }

    public function test_channel_orders_reports_null_fee_when_the_channel_has_no_fee_integration_yet(): void
    {
        $order = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/channels/shopee/orders');

        $response->assertOk();
        $orders = $response->json('orders');

        $this->assertCount(1, $orders);
        $this->assertNull($orders[0]['fee_amount']);
        $this->assertNull($orders[0]['net_amount']);
        $this->assertNull($orders[0]['shipping_method']);
    }

    public function test_queue_returns_only_todays_paid_orders_in_descending_order_with_all_products(): void
    {
        $older = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-1', 'shipping_name' => 'Cliente Antigo']);
        $older->items()->create(['product_name' => 'Produto A', 'product_price' => 50, 'quantity' => 1, 'subtotal' => 50]);

        $newest = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP-2', 'shipping_name' => 'Cliente Novo']);
        // 2 produtos diferentes no mesmo pedido — exatamente o cenário que
        // motivou "listar todos os produtos" em vez de só o primeiro (ver
        // commit 6cfa401 do painel web).
        $newest->items()->create(['product_name' => 'Produto B', 'product_price' => 30, 'quantity' => 2, 'subtotal' => 60]);
        $newest->items()->create(['product_name' => 'Produto C', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        // Não deve aparecer: pedido de ontem (filtro "só hoje", exclusivo
        // desse endpoint) e pedido de hoje mas já enviado (não é mais fila
        // de expedição). created_at não está em Order::$fillable, então
        // nem create() nem update() conseguem setá-lo — só forceFill()
        // ignora esse limite de propósito (bug real encontrado escrevendo
        // este teste: o ->update(['created_at' => ...]) que
        // PrintJobControllerTest usa também não funciona de verdade, só
        // nunca quebrou nenhuma asserção lá porque aquele teste não
        // depende de cruzar a virada do dia).
        $yesterday = $this->makeOrder();
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();
        $this->makeOrder(['status' => Order::STATUS_SHIPPED]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = $response->json('queue');

        $this->assertCount(2, $queue);

        $this->assertSame($newest->id, $queue[0]['id']);
        $this->assertSame('SHP-2', $queue[0]['external_order_id']);
        $this->assertSame(Order::ORIGIN_SHOPEE, $queue[0]['channel']);
        $this->assertSame('Cliente Novo', $queue[0]['customer_name']);
        $this->assertSame(3, $queue[0]['units_count']);
        $this->assertCount(2, $queue[0]['products']);

        $this->assertSame($older->id, $queue[1]['id']);
        $this->assertSame(1, $queue[1]['units_count']);
    }

    public function test_queue_still_includes_packed_orders_flagged_via_packed_at(): void
    {
        // Pedido explícito 2026-08-13: embalar NÃO tira o pedido da lista —
        // o app só troca a cor/texto do botão pra "Embalado" usando este
        // campo, a query continua trazendo todo pedido pago de hoje.
        $pending = $this->makeOrder(['external_order_id' => 'PENDING-1']);
        $packed = $this->makeOrder(['external_order_id' => 'PACKED-1']);
        $packed->forceFill(['packed_at' => now()])->save();

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = collect($response->json('queue'))->keyBy('id');

        $this->assertCount(2, $queue);
        $this->assertNull($queue[$pending->id]['packed_at']);
        $this->assertNotNull($queue[$packed->id]['packed_at']);
    }

    public function test_pack_order_marks_it_packed_without_removing_it_from_the_queue(): void
    {
        $order = $this->makeOrder(['external_order_id' => 'ML-1']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/print-agent/dashboard/queue/{$order->id}/pack");

        $response->assertOk();
        $this->assertNotNull($response->json('packed_at'));

        $order->refresh();
        $this->assertNotNull($order->packed_at);
        // status é a visão do canal sobre o pedido (paid/shipped/...) —
        // embalar não mexe nela, ver comentário da migration.
        $this->assertSame(Order::STATUS_PAID, $order->status);

        $queue = $this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/queue')
            ->json('queue');

        $this->assertCount(1, $queue);
        $this->assertSame($order->id, $queue[0]['id']);
        $this->assertNotNull($queue[0]['packed_at']);
    }

    public function test_pack_order_is_idempotent_on_a_second_call(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->authHeaders())->postJson("/api/print-agent/dashboard/queue/{$order->id}/pack")->assertOk();
        $firstPackedAt = $order->refresh()->packed_at;

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/print-agent/dashboard/queue/{$order->id}/pack")
            ->assertOk();

        $this->assertTrue($firstPackedAt->equalTo($order->refresh()->packed_at));
    }

    public function test_pack_order_rejects_an_order_that_is_not_paid(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_AWAITING_PAYMENT]);

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/print-agent/dashboard/queue/{$order->id}/pack")
            ->assertStatus(409);

        $this->assertNull($order->refresh()->packed_at);
    }

    /**
     * Pedido explícito 2026-08-14 (achado real no pedido #278): venda de
     * Coleta/Places do Mercado Livre com etiqueta liberada só perto de uma
     * data futura decidida pelo canal (scheduled_for) — sem essa lista,
     * ficava indistinguível de um pedido travado de verdade.
     */
    public function test_scheduled_shipments_lists_orders_with_a_future_scheduled_for(): void
    {
        $scheduled = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-SCHED-1', 'shipping_name' => 'Cliente Agendado']);
        $scheduled->items()->create(['product_name' => 'Produto A', 'product_price' => 50, 'quantity' => 2, 'subtotal' => 100]);
        ChannelShipment::create([
            'order_id' => $scheduled->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'shipping_method' => 'xd_drop_off',
            'confirmed_at' => now(),
            'scheduled_for' => now()->addDays(3),
        ]);

        $overdue = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-SCHED-2', 'shipping_name' => 'Cliente Atrasado']);
        ChannelShipment::create([
            'order_id' => $overdue->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'shipping_method' => 'xd_drop_off',
            'confirmed_at' => now()->subDays(2),
            'scheduled_for' => now()->subDay(),
        ]);

        // Continua aparecendo mesmo já "embalado" (packed_at) — achado real
        // no próprio pedido #278: embalar é sobre a caixa estar pronta,
        // sem relação com o canal ter liberado a etiqueta de verdade.
        // Confirmado ao vivo (estava embalado havia horas e a etiqueta
        // continuava tão agendada quanto antes).
        $packedButStillScheduled = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-SCHED-3']);
        $packedButStillScheduled->forceFill(['packed_at' => now()])->save();
        ChannelShipment::create([
            'order_id' => $packedButStillScheduled->id, 'channel' => Order::ORIGIN_MERCADO_LIVRE, 'status' => ChannelShipment::STATUS_CONFIRMED,
            'shipping_method' => 'xd_drop_off', 'confirmed_at' => now(), 'scheduled_for' => now()->addDays(3),
        ]);

        // Não deve aparecer: etiqueta já pronta (saiu da janela de
        // "aguardando", nem que o canal tenha mandado scheduled_for em
        // algum momento — o problema que essa lista existe pra sinalizar
        // já não existe mais).
        $labelReady = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-SCHED-4']);
        ChannelShipment::create([
            'order_id' => $labelReady->id, 'channel' => Order::ORIGIN_MERCADO_LIVRE, 'status' => ChannelShipment::STATUS_LABEL_READY,
            'shipping_method' => 'xd_drop_off', 'confirmed_at' => now(), 'scheduled_for' => now()->addDays(3),
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/scheduled-shipments');

        $response->assertOk();
        $result = collect($response->json('scheduled_shipments'))->keyBy('order_id');

        $this->assertCount(3, $result);
        $this->assertArrayNotHasKey($labelReady->id, $result);

        $this->assertSame('Cliente Agendado', $result[$scheduled->id]['customer_name']);
        $this->assertFalse($result[$scheduled->id]['is_overdue']);
        $this->assertCount(1, $result[$scheduled->id]['products']);

        $this->assertTrue($result[$overdue->id]['is_overdue']);
        $this->assertArrayHasKey($packedButStillScheduled->id, $result);
    }
}

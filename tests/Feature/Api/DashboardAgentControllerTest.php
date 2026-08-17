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
use Illuminate\Support\Facades\Storage;
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
        // subtotal igual a total em todos os pedidos deste teste (sem
        // frete) — revenue_today soma 'subtotal', não 'total' (que inclui
        // frete e não é receita do vendedor, ver comentário no controller).
        $mlOrder = $this->makeOrder(['status' => Order::STATUS_PAID, 'subtotal' => 150, 'total' => 150, 'origin' => Order::ORIGIN_MERCADO_LIVRE]);
        OrderChannelFee::create([
            'order_id' => $mlOrder->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'gross_amount' => 150,
            'fee_amount' => 20,
            'source' => OrderChannelFee::SOURCE_API,
            'computed_at' => now(),
        ]);
        $this->makeOrder(['status' => Order::STATUS_COMPLETED, 'subtotal' => 50, 'total' => 50]);
        $this->makeOrder(['status' => Order::STATUS_CANCELLED, 'subtotal' => 30, 'total' => 30]);
        $this->makeOrder(['status' => Order::STATUS_AWAITING_PAYMENT, 'subtotal' => 999, 'total' => 999]);

        $refundedOrder = $this->makeOrder(['status' => Order::STATUS_PAID, 'subtotal' => 80, 'total' => 80]);
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

    /**
     * BUG REAL 2026-08-15 — cobertura de regressão. Pedido #305 Shopee real:
     * subtotal 44.99, shipping_cost 13.25, total 58.24 (subtotal+frete,
     * correto pro valor da nota fiscal — ver ShopeeDriver::importOrder()).
     * O dashboard do KoraSync mostrava 58.24 como "faturado hoje", mas o
     * Seller Center da Shopee (e o dinheiro que realmente cai pro
     * vendedor) mostra 44.99 — o frete pago ao transportador nunca foi
     * receita do vendedor. revenue_today tem que somar subtotal, não total.
     */
    public function test_metrics_revenue_excludes_shipping_cost_paid_to_the_carrier(): void
    {
        $this->makeOrder([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_SHOPEE,
            'subtotal' => 44.99,
            'shipping_cost' => 13.25,
            'total' => 58.24,
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/metrics');

        $response->assertOk();
        $response->assertJson(['revenue_today' => 44.99]);
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

    public function test_queue_returns_todays_orders_of_any_status_in_descending_order_with_all_products(): void
    {
        $older = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-1', 'shipping_name' => 'Cliente Antigo']);
        $older->items()->create(['product_name' => 'Produto A', 'product_price' => 50, 'quantity' => 1, 'subtotal' => 50]);

        $newest = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP-2', 'shipping_name' => 'Cliente Novo']);
        // 2 produtos diferentes no mesmo pedido — exatamente o cenário que
        // motivou "listar todos os produtos" em vez de só o primeiro (ver
        // commit 6cfa401 do painel web).
        $newest->items()->create(['product_name' => 'Produto B', 'product_price' => 30, 'quantity' => 2, 'subtotal' => 60]);
        $newest->items()->create(['product_name' => 'Produto C', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        // Pedido explícito 2026-08-15 ("quero todos os pedidos aparecendo
        // hoje"): já enviado TEM que aparecer agora — só status foi
        // removido do filtro, a data continua exclusiva desse endpoint.
        $shipped = $this->makeOrder(['status' => Order::STATUS_SHIPPED, 'external_order_id' => 'SHIPPED-1']);

        // Não deve aparecer: pedido de ONTEM já enviado (nem hoje, nem
        // "pago ainda não embalado" — ver teste dedicado abaixo pra esse
        // segundo caso, corrigido em 2026-08-17).
        // created_at não está em Order::$fillable, então nem create() nem
        // update() conseguem setá-lo — só forceFill() ignora esse limite
        // de propósito (bug real encontrado escrevendo este teste: o
        // ->update(['created_at' => ...]) que PrintJobControllerTest usa
        // também não funciona de verdade, só nunca quebrou nenhuma
        // asserção lá porque aquele teste não depende de cruzar a virada
        // do dia).
        $yesterday = $this->makeOrder(['status' => Order::STATUS_SHIPPED]);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = $response->json('queue');

        $this->assertCount(3, $queue);

        $this->assertSame($shipped->id, $queue[0]['id']);
        $this->assertSame(Order::STATUS_SHIPPED, $queue[0]['status']);
        $this->assertSame('Enviado', $queue[0]['status_label']);

        $this->assertSame($newest->id, $queue[1]['id']);
        $this->assertSame('SHP-2', $queue[1]['external_order_id']);
        $this->assertSame(Order::ORIGIN_SHOPEE, $queue[1]['channel']);
        $this->assertSame('Cliente Novo', $queue[1]['customer_name']);
        $this->assertSame(3, $queue[1]['units_count']);
        $this->assertCount(2, $queue[1]['products']);
        $this->assertSame(Order::STATUS_PAID, $queue[1]['status']);
        $this->assertSame('Pago', $queue[1]['status_label']);

        $this->assertSame($older->id, $queue[2]['id']);
        $this->assertSame(1, $queue[2]['units_count']);
    }

    public function test_queue_keeps_showing_a_paid_order_from_yesterday_until_it_is_packed(): void
    {
        // Bug real relatado 2026-08-17: pedido pago ONTEM e ainda não
        // embalado sumia da fila na virada do dia, mesmo continuando "em
        // preparação" de verdade — o corte "só hoje" (pedido explícito
        // 2026-08-15) não previa esse caso. Fix: pedido pago sem
        // packed_at continua aparecendo, não importa a data.
        $unpackedYesterday = $this->makeOrder(['external_order_id' => 'YESTERDAY-UNPACKED']);
        $unpackedYesterday->forceFill(['created_at' => now()->subDay()])->save();

        // Continua sumindo assim que for embalado, mesmo sendo de ontem.
        $packedYesterday = $this->makeOrder(['external_order_id' => 'YESTERDAY-PACKED']);
        $packedYesterday->forceFill(['created_at' => now()->subDay(), 'packed_at' => now()->subDay()])->save();

        // Continua sumindo se deixou de estar pago (enviado), mesmo sem
        // packed_at, mesmo sendo de ontem.
        $shippedYesterday = $this->makeOrder(['status' => Order::STATUS_SHIPPED, 'external_order_id' => 'YESTERDAY-SHIPPED']);
        $shippedYesterday->forceFill(['created_at' => now()->subDay()])->save();

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = collect($response->json('queue'))->keyBy('id');

        $this->assertTrue($queue->has($unpackedYesterday->id));
        $this->assertNull($queue[$unpackedYesterday->id]['packed_at']);
        $this->assertFalse($queue->has($packedYesterday->id));
        $this->assertFalse($queue->has($shippedYesterday->id));
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

    /**
     * Pedido explícito 2026-08-15: foto do produto pro card do KoraSync —
     * a mesma imagem local usada pra publicar nos marketplaces (ver
     * OrderImageArchiveService), servida como PNG, e arquivada em disco na
     * hierarquia Ano/Mês/Dia/Canal/id_pedido.png.
     */
    public function test_queue_order_image_returns_the_products_primary_image_as_png(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $product = \App\Modules\Catalog\Models\Product::factory()->create();
        \App\Modules\Catalog\Models\ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/secondary.jpg',
            'position' => 1,
            'is_primary' => false,
        ]);
        $primary = \App\Modules\Catalog\Models\ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/primary.jpg',
            'position' => 0,
            'is_primary' => true,
        ]);

        $fakeJpeg = imagecreate(4, 4);
        ob_start();
        imagejpeg($fakeJpeg);
        Storage::disk('public')->put($primary->path, ob_get_clean());
        imagedestroy($fakeJpeg);

        $order = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        $response = $this->withHeaders($this->authHeaders())
            ->get("/api/print-agent/dashboard/queue/{$order->id}/image");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $response->getContent());

        $expectedPath = sprintf('order-images/%s/%s/%s/shopee/%d.png', $order->created_at->format('Y'), $order->created_at->format('m'), $order->created_at->format('d'), $order->id);
        Storage::disk('local')->assertExists($expectedPath);
    }

    public function test_queue_order_image_returns_404_when_the_order_has_no_product_image(): void
    {
        $order = $this->makeOrder();
        // Item sem product_id — emissão manual/serviço avulso, sem foto pra mostrar.
        $order->items()->create(['product_id' => null, 'product_name' => 'Serviço avulso', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        $this->withHeaders($this->authHeaders())
            ->get("/api/print-agent/dashboard/queue/{$order->id}/image")
            ->assertNotFound();
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

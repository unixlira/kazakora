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
            'sales_yesterday' => 0,
            'cancelled_today' => 1,
            'refunded_today' => 1,
            'cart_items_count' => 5,
            // 280 de bruto - 20 de taxa real (só o pedido do ML tem taxa
            // capturada) - os outros dois pedidos pagos hoje não têm
            // OrderChannelFee, então entram sem desconto nenhum.
            'net_profit_today' => 260.0,
            // 1 cancelado + 1 devolvido (Payment estornado) neste mês —
            // card "Cancelamentos e devoluções do mês" do KoraSync v2.0.
            'cancellations_and_returns_month' => 2,
            'packed_today' => 0,
            'shipped_today' => 0,
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

    /**
     * BUG REAL 2026-08-17 ("as métricas não estão funcionando", achado
     * varrendo todo painel atrás do mesmo bug já corrigido em metrics()
     * acima 2 dias antes): channels() somava SUM(total) por canal — o
     * mesmo problema do frete, só que num lugar diferente do mesmo
     * endpoint. O card por canal (revenue_today/revenue_month) fica na
     * MESMA tela do KoraSync que os cards de topo (vindos de metrics(),
     * já corretos) — somar os cards por canal dava mais que o card de
     * topo sempre que houvesse pedido com frete.
     */
    public function test_channels_revenue_excludes_shipping_cost_paid_to_the_carrier(): void
    {
        $this->makeOrder([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_SHOPEE,
            'subtotal' => 44.99,
            'shipping_cost' => 13.25,
            'total' => 58.24,
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/channels');

        $response->assertOk();
        $channel = collect($response->json('channels'))->firstWhere('channel', Order::ORIGIN_SHOPEE);

        $this->assertSame(44.99, $channel['revenue_today']);
        $this->assertSame(44.99, $channel['revenue_month']);
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

        // Não deve aparecer: pedido de ANTEONTEM, fora da janela
        // "ontem + hoje" (corte explícito do usuário 2026-08-17).
        // created_at não está em Order::$fillable, então nem create() nem
        // update() conseguem setá-lo — só forceFill() ignora esse limite
        // de propósito (bug real encontrado escrevendo este teste: o
        // ->update(['created_at' => ...]) que PrintJobControllerTest usa
        // também não funciona de verdade, só nunca quebrou nenhuma
        // asserção lá porque aquele teste não depende de cruzar a virada
        // do dia).
        $twoDaysAgo = $this->makeOrder(['status' => Order::STATUS_SHIPPED]);
        $twoDaysAgo->forceFill(['created_at' => now()->subDays(2)])->save();

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = $response->json('queue');

        $this->assertCount(3, $queue);
        $this->assertFalse(collect($queue)->contains('id', $twoDaysAgo->id));

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

    public function test_queue_includes_yesterdays_orders_of_any_status_but_not_older_ones(): void
    {
        // Bug real relatado 2026-08-17: pedido pago ONTEM e ainda não
        // embalado sumia da fila na virada do dia, mesmo continuando "em
        // preparação" de verdade — o corte "só hoje" (pedido explícito
        // 2026-08-15) não previa esse caso. Primeira correção tentativa foi
        // "pago sem packed_at, sem limite de data", mas trouxe de volta um
        // represamento de semanas — revertida a favor do corte explícito
        // pedido pelo usuário no mesmo dia: só ONTEM + HOJE, qualquer
        // status, igual já era só pra hoje.
        $unpackedYesterday = $this->makeOrder(['external_order_id' => 'YESTERDAY-UNPACKED']);
        $unpackedYesterday->forceFill(['created_at' => now()->subDay()])->save();

        // Continua aparecendo mesmo já embalado (packed_at não filtra a
        // query, pedido 2026-08-13) ou já enviado (pedido 2026-08-15) —
        // desde que dentro da janela ontem+hoje.
        $packedYesterday = $this->makeOrder(['external_order_id' => 'YESTERDAY-PACKED']);
        $packedYesterday->forceFill(['created_at' => now()->subDay(), 'packed_at' => now()->subDay()])->save();

        $shippedYesterday = $this->makeOrder(['status' => Order::STATUS_SHIPPED, 'external_order_id' => 'YESTERDAY-SHIPPED']);
        $shippedYesterday->forceFill(['created_at' => now()->subDay()])->save();

        // Fora da janela: anteontem, mesmo pago e não embalado.
        $twoDaysAgo = $this->makeOrder(['external_order_id' => 'TWO-DAYS-AGO']);
        $twoDaysAgo->forceFill(['created_at' => now()->subDays(2)])->save();

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = collect($response->json('queue'))->keyBy('id');

        $this->assertTrue($queue->has($unpackedYesterday->id));
        $this->assertNull($queue[$unpackedYesterday->id]['packed_at']);
        $this->assertTrue($queue->has($packedYesterday->id));
        $this->assertTrue($queue->has($shippedYesterday->id));
        $this->assertFalse($queue->has($twoDaysAgo->id));
    }

    /**
     * Pedido explícito 2026-08-17: venda com entrega programada (Mercado
     * Livre) entra na fila do DIA AGENDADO, não do dia da venda — a venda
     * pode ter saído há semanas, mas o canal só libera a etiqueta perto da
     * data agendada, e é nesse dia que o operador precisa vê-la. Payload
     * ganha 'scheduled_for'/'label_ready' pro app decidir o 3º estado do
     * botão ("Sem Etiqueta").
     */
    public function test_queue_includes_a_scheduled_order_on_its_scheduled_day_regardless_of_when_it_was_sold(): void
    {
        $scheduledForToday = $this->makeOrder(['external_order_id' => 'ML-SCHEDULED-TODAY']);
        $scheduledForToday->forceFill(['created_at' => now()->subWeeks(2)])->save();
        ChannelShipment::create([
            'order_id' => $scheduledForToday->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_method' => 'xd_drop_off',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now()->subWeeks(2),
            'scheduled_for' => now(),
        ]);

        // Continua fora: agendado pra depois de amanhã, ainda não chegou o
        // dia — mesma janela que já vale pra created_at.
        $scheduledForFuture = $this->makeOrder(['external_order_id' => 'ML-SCHEDULED-FUTURE']);
        $scheduledForFuture->forceFill(['created_at' => now()->subWeeks(2)])->save();
        ChannelShipment::create([
            'order_id' => $scheduledForFuture->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_method' => 'xd_drop_off',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now()->subWeeks(2),
            'scheduled_for' => now()->addDays(3),
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = collect($response->json('queue'))->keyBy('id');

        $this->assertTrue($queue->has($scheduledForToday->id));
        $this->assertNotNull($queue[$scheduledForToday->id]['scheduled_for']);
        $this->assertFalse($queue[$scheduledForToday->id]['label_ready']);
        $this->assertFalse($queue->has($scheduledForFuture->id));
    }

    /**
     * BUG REAL 2026-08-29 (relatado pelo usuário: venda do Mercado Livre
     * aparecendo na fila de preparação antes da data agendada) — pedido
     * vendido HOJE mas com entrega agendada pra semana que vem batia na
     * condição de created_at (hoje) e entrava na fila mesmo faltando dias
     * pra etiqueta liberar. Cobertura específica desse cenário (venda de
     * HOJE + agendamento futuro), diferente do teste acima (venda de
     * SEMANAS atrás + agendamento futuro) — o bug só aparecia quando
     * created_at também caía dentro da janela ontem/hoje.
     */
    public function test_queue_excludes_a_scheduled_order_sold_today_when_its_scheduled_day_has_not_arrived(): void
    {
        $soldTodayScheduledForFuture = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-SOLD-TODAY-FUTURE']);
        ChannelShipment::create([
            'order_id' => $soldTodayScheduledForFuture->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_method' => 'xd_drop_off',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'scheduled_for' => now()->addDays(5),
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = collect($response->json('queue'))->keyBy('id');
        $outOfStock = collect($response->json('out_of_stock'))->keyBy('id');

        $this->assertFalse($queue->has($soldTodayScheduledForFuture->id));
        $this->assertFalse($outOfStock->has($soldTodayScheduledForFuture->id));
    }

    public function test_queue_marks_label_ready_true_once_the_channel_releases_the_scheduled_label(): void
    {
        $order = $this->makeOrder(['external_order_id' => 'ML-SCHEDULED-READY']);
        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_method' => 'xd_drop_off',
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'confirmed_at' => now(),
            'scheduled_for' => now(),
            'label_ready_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = collect($response->json('queue'))->keyBy('id');

        $this->assertTrue($queue[$order->id]['label_ready']);
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

    /**
     * KoraSync v2.0 (pedido explícito 2026-08-29): pedido sem estoque
     * suficiente do produto vai pra 'out_of_stock' em vez de 'queue' — FIFO
     * por id (quem vendeu primeiro consome o estoque disponível primeiro).
     */
    public function test_queue_splits_orders_between_normal_and_out_of_stock_by_available_stock(): void
    {
        $product = \App\Modules\Catalog\Models\Product::factory()->create(['stock' => 5, 'sku' => 'SKU-1']);

        // Vendeu primeiro (id menor) — consome todo o estoque disponível.
        $covered = $this->makeOrder(['external_order_id' => 'COVERED']);
        $covered->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 5, 'subtotal' => 50]);

        // Vendeu depois — não sobrou estoque nenhum pra este.
        $shortfall = $this->makeOrder(['external_order_id' => 'SHORTFALL']);
        $shortfall->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 2, 'subtotal' => 20]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $queue = collect($response->json('queue'))->keyBy('id');
        $outOfStock = collect($response->json('out_of_stock'))->keyBy('id');

        $this->assertTrue($queue->has($covered->id));
        $this->assertSame([], $queue[$covered->id]['stock_shortage']);

        $this->assertFalse($queue->has($shortfall->id));
        $this->assertTrue($outOfStock->has($shortfall->id));
        $this->assertSame('SKU-1', $outOfStock[$shortfall->id]['stock_shortage'][0]['sku']);
        $this->assertSame(2, $outOfStock[$shortfall->id]['stock_shortage'][0]['missing']);

        $this->assertSame(2, $response->json('pending_separation_count'));
    }

    /**
     * BUG REAL relatado pelo usuário 2026-08-29: 2 vendas sem estoque
     * (controle de Xbox, Shopee) vendidas fora da janela ontem/hoje
     * sumiam da aba "Sem Estoque" por causa do mesmo corte de data que só
     * faz sentido pra Fila normal ("hoje só") — "Sem Estoque" é uma fila
     * de reposição em aberto, mas com limite: a partir do início do mês
     * corrente (2ª correção no mesmo dia, pedido explícito: "sem estoque
     * deve ser mostrado a partir desse mês em diante" — a 1ª correção,
     * "sem limite nenhum", foi longe demais e trazia venda de meses atrás
     * já enviada/concluída, ver teste abaixo).
     */
    public function test_out_of_stock_orders_are_shown_from_the_start_of_the_month_but_not_before(): void
    {
        $product = \App\Modules\Catalog\Models\Product::factory()->create(['stock' => 0, 'sku' => 'XBOX-CTRL']);

        // Início do mês corrente (limite INCLUSIVE) — ainda deve aparecer.
        $earlyThisMonth = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHOPEE-XBOX-EARLY-MONTH']);
        $earlyThisMonth->forceFill(['created_at' => now()->startOfMonth()])->save();
        $earlyThisMonth->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        // 2 dias antes do início do mês (folga de propósito, pra nunca
        // colidir com o carry-over de "ontem" perto da virada — ver teste
        // de virada de mês logo abaixo) — não deve aparecer em lugar nenhum.
        $beforeThisMonth = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHOPEE-XBOX-BEFORE-MONTH']);
        $beforeThisMonth->forceFill(['created_at' => now()->startOfMonth()->subDays(2)])->save();
        $beforeThisMonth->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $outOfStock = collect($response->json('out_of_stock'))->keyBy('id');
        $queue = collect($response->json('queue'))->keyBy('id');

        $this->assertTrue($outOfStock->has($earlyThisMonth->id));
        $this->assertSame('XBOX-CTRL', $outOfStock[$earlyThisMonth->id]['stock_shortage'][0]['sku']);
        $this->assertFalse($queue->has($earlyThisMonth->id));

        $this->assertFalse($outOfStock->has($beforeThisMonth->id));
        $this->assertFalse($queue->has($beforeThisMonth->id));
    }

    /**
     * BUG REAL 2026-08-15 (o carry-over de "ontem" — ver isInTodayWindow) +
     * o corte mensal novo (2026-08-29) não podem se contradizer: se HOJE é
     * dia 1º do mês, "ontem" cai no mês anterior — um pedido pago de ontem
     * ainda não embalado precisa continuar entrando na conta de estoque
     * mesmo assim. Carbon::setTestNow() simula esse instante exato, não dá
     * pra depender de rodar o teste justo no dia 1º de verdade.
     */
    public function test_actionable_orders_still_include_yesterday_when_today_is_the_first_of_the_month(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-09-01 10:00:00');

        try {
            $product = \App\Modules\Catalog\Models\Product::factory()->create(['stock' => 0, 'sku' => 'XBOX-CTRL-3']);

            $yesterdayLastMonth = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHOPEE-XBOX-YESTERDAY-LAST-MONTH']);
            $yesterdayLastMonth->forceFill(['created_at' => now()->subDay()])->save(); // 2026-08-31
            $yesterdayLastMonth->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

            $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

            $response->assertOk();
            $this->assertTrue(collect($response->json('out_of_stock'))->contains('id', $yesterdayLastMonth->id));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    /**
     * BUG REAL relatado pelo usuário 2026-08-29 ("está aparecendo vendas
     * antigas já entregues"): a 1ª correção do dia usava "status !=
     * cancelled" pra decidir quem entra na conta de estoque — isso incluía
     * pedido já ENVIADO/CONCLUÍDO sem packed_at (resolvido antes desse
     * campo existir, ou por fora do KoraSync), fazendo o sistema achar que
     * uma venda já entregue ainda "precisava de estoque". Corrigido pra só
     * status PAID entrar na conta — os outros (mesmo sem packed_at) não
     * fazem parte da fila de separação de jeito nenhum, só aparecem como
     * está na Fila normal (se caírem na janela ontem/hoje).
     */
    public function test_out_of_stock_never_includes_an_already_shipped_order_even_without_packed_at(): void
    {
        $product = \App\Modules\Catalog\Models\Product::factory()->create(['stock' => 0, 'sku' => 'XBOX-CTRL-4']);

        $shippedNoPackedAt = $this->makeOrder(['status' => Order::STATUS_SHIPPED, 'origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHOPEE-XBOX-ALREADY-SHIPPED']);
        $shippedNoPackedAt->forceFill(['created_at' => now()->startOfMonth()])->save();
        $shippedNoPackedAt->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $this->assertFalse(collect($response->json('out_of_stock'))->contains('id', $shippedNoPackedAt->id));
    }

    /**
     * KoraSync v2.0: assim que o estoque é reposto, o próximo poll (2s) já
     * recalcula e o pedido sobe sozinho pra Fila normal — sem nenhuma ação
     * manual de "mover" entre as abas (cálculo em tempo real, sem estado
     * persistido, ver partitionByStock() no controller).
     */
    public function test_out_of_stock_order_moves_back_to_normal_queue_once_restocked(): void
    {
        $product = \App\Modules\Catalog\Models\Product::factory()->create(['stock' => 0, 'sku' => 'SKU-2']);
        $order = $this->makeOrder(['external_order_id' => 'RESTOCK-ME']);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 3, 'subtotal' => 30]);

        $before = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');
        $this->assertTrue(collect($before->json('out_of_stock'))->contains('id', $order->id));
        $this->assertFalse(collect($before->json('queue'))->contains('id', $order->id));

        $product->update(['stock' => 3]);

        $after = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');
        $this->assertTrue(collect($after->json('queue'))->contains('id', $order->id));
        $this->assertFalse(collect($after->json('out_of_stock'))->contains('id', $order->id));
    }

    /**
     * BUG REAL 2026-08-29, relatado pelo usuário (pedido #913 — venda
     * lançada errada, cancelada de propósito pra sumir da fila): antes
     * dessa correção, CANCELLED caía na mesma regra de "auditoria do dia"
     * que enviado/concluído/aguardando pagamento e continuava aparecendo
     * na Fila normal mesmo depois de cancelado.
     */
    public function test_cancelled_order_does_not_appear_in_the_queue_even_within_todays_window(): void
    {
        $cancelled = $this->makeOrder(['status' => Order::STATUS_CANCELLED, 'external_order_id' => 'CANCELLED-913']);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue');

        $response->assertOk();
        $this->assertFalse(collect($response->json('queue'))->contains('id', $cancelled->id));
        $this->assertFalse(collect($response->json('out_of_stock'))->contains('id', $cancelled->id));
    }

    /**
     * Mesmo bug do teste acima, só que na aba de "vendas futuras agendadas"
     * do Mercado Livre (scheduledShipments()) — um pedido com scheduled_for
     * que foi cancelado não precisa de nenhuma ação, não devia continuar
     * aparecendo aqui pra sempre.
     */
    public function test_cancelled_order_does_not_appear_in_scheduled_shipments(): void
    {
        $cancelled = $this->makeOrder([
            'status' => Order::STATUS_CANCELLED,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-CANCELLED-SCHEDULED',
        ]);

        ChannelShipment::create([
            'order_id' => $cancelled->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'status' => ChannelShipment::STATUS_PENDING,
            'scheduled_for' => now()->addDays(3),
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/scheduled-shipments');

        $response->assertOk();
        $this->assertFalse(collect($response->json('scheduled_shipments'))->contains('order_id', $cancelled->id));
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

    /**
     * Aba "Mercado Livre" do KoraSync v2.0 (pedido explícito 2026-08-29):
     * ?channel= filtra a mesma lista pra só um canal, sem mudar o
     * comportamento padrão (sem o parâmetro) usado pelos testes acima.
     */
    public function test_scheduled_shipments_filters_by_channel_query_param(): void
    {
        $ml = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-FUT']);
        ChannelShipment::create([
            'order_id' => $ml->id, 'channel' => Order::ORIGIN_MERCADO_LIVRE, 'status' => ChannelShipment::STATUS_CONFIRMED,
            'shipping_method' => 'xd_drop_off', 'confirmed_at' => now(), 'scheduled_for' => now()->addDays(2),
        ]);

        $shopee = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP-FUT']);
        ChannelShipment::create([
            'order_id' => $shopee->id, 'channel' => Order::ORIGIN_SHOPEE, 'status' => ChannelShipment::STATUS_CONFIRMED,
            'shipping_method' => 'xd_drop_off', 'confirmed_at' => now(), 'scheduled_for' => now()->addDays(2),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/scheduled-shipments?channel=mercado_livre');

        $response->assertOk();
        $result = collect($response->json('scheduled_shipments'))->keyBy('order_id');

        $this->assertTrue($result->has($ml->id));
        $this->assertFalse($result->has($shopee->id));
    }
}

<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintJobControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createPrintJob(string $status, string $origin = 'shopee'): PrintJob
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => $origin,
            'external_order_id' => 'VENDA-'.uniqid(),
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => 'Não informado',
            'shipping_zip' => '00000000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => 'S/N',
            'shipping_neighborhood' => 'Não informado',
            'shipping_city' => 'Não informado',
            'shipping_state' => 'SP',
            'subtotal' => 0,
            'shipping_cost' => 0,
            'total' => 0,
        ]);

        return PrintJob::create([
            'order_id' => $order->id,
            'label_path' => 'labels/teste.pdf',
            'status' => $status,
        ]);
    }

    public function test_only_admin_can_view_the_dispatch_panel(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($manager)->get('/admin/impressoes')->assertForbidden();
        $this->actingAs($admin)->get('/admin/impressoes')->assertOk();
    }

    /**
     * Painel de expedição (2026-08-05): fila é por Order (status paid), não
     * mais por PrintJob — mostra quantidade de UNIDADES (soma de quantity),
     * não linhas de item, decrescente por data. Cobre o bug real que
     * motivou a mudança: pedido com 1 linha mas quantity=2 tem que aparecer
     * como "2 produtos pra separar", não 1.
     */
    public function test_dispatch_queue_shows_paid_orders_with_unit_count_descending(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $older = $this->makeOrder(Order::STATUS_PAID, 'shopee');
        $older->items()->create(['product_name' => 'Caneca', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);
        $older->update(['created_at' => now()->subMinutes(10)]);

        // Criado depois, com created_at real (agora) — garante ordem
        // determinística sem depender de timing de execução do teste.
        $newer = $this->makeOrder(Order::STATUS_PAID, 'mercado_livre');
        $newer->items()->create(['product_name' => 'Fone Bluetooth', 'product_price' => 50, 'quantity' => 2, 'subtotal' => 100]);

        $shipped = $this->makeOrder(Order::STATUS_SHIPPED); // já embalado — não deve aparecer na fila

        $response = $this->actingAs($admin)->get('/admin/impressoes');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('queue', 2)
            ->where('queue.0.id', $newer->id)
            ->where('queue.0.unitsCount', 2)
            ->where('queue.0.products.0', '2x Fone Bluetooth')
            ->where('queue.1.id', $older->id)
            ->where('queue.1.unitsCount', 1));
    }

    /**
     * Pedido explícito 2026-08-21: venda do Mercado Livre com entrega
     * programada pra uma data FUTURA não deve aparecer na fila de
     * separação ainda (a etiqueta real só libera perto da data). Some da
     * fila enquanto scheduled_for for futuro, reaparece sozinho no dia
     * (testado abaixo) — sem precisar de status novo nenhum.
     */
    public function test_dispatch_queue_hides_a_mercado_livre_order_scheduled_for_a_future_date(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $futureScheduled = $this->makeOrder(Order::STATUS_PAID, 'mercado_livre');
        $futureScheduled->items()->create(['product_name' => 'Produto agendado', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);
        ChannelShipment::create([
            'order_id' => $futureScheduled->id,
            'channel' => 'mercado_livre',
            'external_shipment_id' => 'SHIP-FUTURO',
            'shipping_method' => 'xd_drop_off',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'scheduled_for' => now()->addDays(3)->startOfDay(),
        ]);

        $normal = $this->makeOrder(Order::STATUS_PAID, 'mercado_livre');
        $normal->items()->create(['product_name' => 'Produto normal', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        $response = $this->actingAs($admin)->get('/admin/impressoes');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('queue', 1)
            ->where('queue.0.id', $normal->id));
    }

    /**
     * Mesmo cenário acima, mas com scheduled_for chegando em HOJE (a
     * etiqueta é liberada perto da data) — reaparece na fila sozinho, sem
     * precisar de nenhuma ação manual.
     */
    public function test_dispatch_queue_shows_a_scheduled_order_once_its_date_arrives(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $scheduledForToday = $this->makeOrder(Order::STATUS_PAID, 'mercado_livre');
        $scheduledForToday->items()->create(['product_name' => 'Produto do dia', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);
        ChannelShipment::create([
            'order_id' => $scheduledForToday->id,
            'channel' => 'mercado_livre',
            'external_shipment_id' => 'SHIP-HOJE',
            'shipping_method' => 'xd_drop_off',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'scheduled_for' => now()->startOfDay(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/impressoes');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('queue', 1)
            ->where('queue.0.id', $scheduledForToday->id));
    }

    public function test_channel_counts_exclude_shein_and_store_origin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeOrder(Order::STATUS_PAID, 'mercado_livre');
        $this->makeOrder(Order::STATUS_PAID, 'shein');
        $this->makeOrder(Order::STATUS_PAID, 'loja');

        $response = $this->actingAs($admin)->get('/admin/impressoes');

        $response->assertInertia(fn ($page) => $page
            ->has('channelCounts', 4)
            ->where('channelCounts.0.channel', 'mercado_livre')
            ->where('channelCounts.0.total', 1));
    }

    private function makeOrder(string $status, string $origin = 'loja'): Order
    {
        return Order::create([
            'status' => $status,
            'origin' => $origin,
            'external_order_id' => $origin === 'loja' ? null : 'VENDA-'.uniqid(),
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => 'Não informado',
            'shipping_zip' => '00000000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => 'S/N',
            'shipping_neighborhood' => 'Não informado',
            'shipping_city' => 'Não informado',
            'shipping_state' => 'SP',
            'subtotal' => 0,
            'shipping_cost' => 0,
            'total' => 0,
        ]);
    }

    public function test_list_shows_channel_and_sale_id(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->createPrintJob(PrintJob::STATUS_PRINTED, 'shopee');

        $response = $this->actingAs($admin)->get('/admin/impressoes/lista');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('jobs.data.0.id', $job->id)
            ->where('jobs.data.0.channel', 'Shopee')
            ->where('jobs.data.0.saleId', $job->order->external_order_id)
        );
    }

    public function test_list_filters_by_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->createPrintJob(PrintJob::STATUS_QUEUED);
        $failed = $this->createPrintJob(PrintJob::STATUS_FAILED);

        $response = $this->actingAs($admin)->get('/admin/impressoes/lista?status=failed');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('jobs.data', 1)
            ->where('jobs.data.0.id', $failed->id)
        );
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * O clique de separar do KoraSync.
 *
 * Nasceu na Fase 3 (2026-09-04) com porteiro de canal e impressão de
 * etiqueta pendurados nele. Em 2026-09-07 o usuário inverteu o fluxo do
 * galpão — imprime-se o lote de etiquetas ANTES e a baixa passou a ser só a
 * baixa — e os dois saíram daqui. O que estes testes guardam agora é
 * justamente isso: separar grava packed_at e mais nada.
 */
class SeparateOrderEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-print-agent-token'];
    }

    private function makeOrder(string $origin, string $status = Order::STATUS_PAID): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'status' => $status,
            'origin' => $origin,
            'external_order_id' => '999888777',
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
    }

    public function test_it_rejects_requests_without_a_valid_token(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar")->assertStatus(401);
    }

    /**
     * MUDANÇA DE FLUXO 2026-09-07: o clique de separar não consulta canal
     * nenhum. Antes ele reconsultava Shopee/Mercado Livre pra pegar um
     * cancelamento de última hora; o usuário mandou tirar isso junto com a
     * impressão ("só dá baixa na separação"). TikTok já era assim.
     */
    public function test_separation_never_calls_the_channel(): void
    {
        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldNotReceive('import');
        $this->app->instance(OrderImportService::class, $importer);

        foreach ([Order::ORIGIN_TIKTOK_SHOP, Order::ORIGIN_MERCADO_LIVRE, Order::ORIGIN_SHOPEE] as $canal) {
            $order = $this->makeOrder($canal);

            $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
                ->assertOk()
                ->assertJson(['result' => 'ok', 'channel_checked' => false]);

            $this->assertNotNull($order->refresh()->packed_at, "Pedido de {$canal} devia ter sido dado baixa.");
        }
    }

    /**
     * O coração da mudança de 2026-09-07: separar NÃO imprime mais nada,
     * nem quando a etiqueta está pronta e guardada esperando. Quem gasta
     * papel agora é o botão "Gerar etiquetas em lote", antes da separação.
     */
    public function test_separation_does_not_print_anything_anymore(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'external_shipment_id' => 'SHIP-9',
            'shipping_method' => 'self_service',
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'confirmed_at' => now(),
            'label_path' => "labels/{$order->id}/etiqueta-9.pdf",
            'label_ready_at' => now(),
        ]);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['result' => 'ok', 'label_queued' => false]);

        $this->assertNotNull($order->refresh()->packed_at);
        $this->assertDatabaseCount('print_jobs', 0);
    }

    /**
     * A separação também não cutuca mais o canal atrás de etiqueta que
     * ainda não saiu — esse empurrão mudou de lugar junto com a impressão
     * (ver BatchLabelPrintService::cutucar()).
     */
    public function test_separation_does_not_kick_the_label_flow_anymore(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertOk();

        Queue::assertNotPushed(ConfirmChannelShippingJob::class);
    }

    /**
     * Quando a etiqueta JÁ saiu antes (o normal no fluxo novo: o lote
     * imprimiu de manhã, a baixa vem depois), a resposta diz a data — é o
     * que o KoraSync mostra pra ninguém ficar esperando papel.
     */
    public function test_separation_reports_when_the_label_already_came_out(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        PrintJob::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'label_path' => "labels/{$order->id}/etiqueta.pdf",
            'status' => PrintJob::STATUS_PRINTED,
            'printed_at' => now()->setTime(8, 30),
        ]);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertOk()
            ->assertJson([
                'result' => 'ok',
                'label_queued' => false,
                'label_already_printed_at' => now()->setTime(8, 30)->format('d/m/Y H:i'),
            ]);
    }

    public function test_an_order_that_is_not_paid_is_refused(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP, Order::STATUS_AWAITING_PAYMENT);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertStatus(409)
            ->assertJson(['result' => 'blocked']);
    }

    /**
     * "Tentar de novo" (2026-09-06): a impressora recusou, o job ficou
     * failed, e até então não havia caminho nenhum pra mandar de novo sem
     * mexer no banco.
     */
    public function test_reprint_requeues_the_same_label_after_a_printer_failure(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_SHOPEE);
        $order->forceFill(['packed_at' => now()])->save();

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'external_shipment_id' => 'SHIP-11',
            'shipping_method' => 'standard',
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'confirmed_at' => now(),
            'label_path' => "labels/{$order->id}/etiqueta-11.pdf",
            'label_ready_at' => now(),
        ]);

        PrintJob::create([
            'order_id' => $order->id,
            'label_path' => "labels/{$order->id}/etiqueta-11.pdf",
            'status' => PrintJob::STATUS_FAILED,
            'error_message' => 'Impressora não está pronta',
        ]);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/reimprimir", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['ok' => true]);

        // Linha NOVA, não a antiga reaberta: o agente da loja ignora id que
        // já viu.
        $this->assertSame(2, PrintJob::where('order_id', $order->id)->count());
        $this->assertSame(1, PrintJob::where('order_id', $order->id)->where('status', PrintJob::STATUS_QUEUED)->count());
    }

    public function test_reprint_does_not_stack_a_second_label_when_one_is_already_waiting(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_SHOPEE);
        $order->forceFill(['packed_at' => now()])->save();

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'external_shipment_id' => 'SHIP-12',
            'shipping_method' => 'standard',
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'confirmed_at' => now(),
            'label_path' => "labels/{$order->id}/etiqueta-12.pdf",
            'label_ready_at' => now(),
        ]);

        PrintJob::create([
            'order_id' => $order->id,
            'label_path' => "labels/{$order->id}/etiqueta-12.pdf",
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/reimprimir", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['ok' => true, 'already_queued' => true]);

        $this->assertSame(1, PrintJob::where('order_id', $order->id)->count());
    }

    /**
     * MUDANÇA DE FLUXO 2026-09-07: reimprimir não espera mais a separação.
     * O caso real é a etiqueta que amassou no lote da manhã, de um pedido
     * que ninguém separou ainda — antes disso ele ficava sem saída.
     */
    public function test_reprint_works_before_the_separation(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'external_shipment_id' => 'SHIP-22',
            'shipping_method' => 'self_service',
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'confirmed_at' => now(),
            'label_path' => "labels/{$order->id}/etiqueta-22.pdf",
            'label_ready_at' => now(),
        ]);

        $this->assertNull($order->packed_at);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/reimprimir", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'status' => PrintJob::STATUS_QUEUED,
        ]);
    }

    public function test_undoing_separation_returns_the_order_to_the_queue(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);
        $order->forceFill(['packed_at' => now()])->save();

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/desfazer-separacao", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['result' => 'ok', 'packed_at' => null]);

        $this->assertNull($order->refresh()->packed_at);
    }

    /**
     * A proteção que importa: pedido que já saiu não volta pra fila, senão
     * o operador separa a mesma caixa duas vezes.
     */
    public function test_undoing_separation_is_refused_for_an_order_that_already_left(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP, Order::STATUS_SHIPPED);
        $order->forceFill(['packed_at' => now()])->save();

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/desfazer-separacao", [], $this->authHeaders())
            ->assertStatus(409)
            ->assertJson(['result' => 'blocked']);

        $this->assertNotNull($order->refresh()->packed_at);
    }

    /** Clique repetido (ou retry de rede) confirma o estado, não estoura. */
    public function test_undoing_a_separation_that_was_already_undone_is_idempotent(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/desfazer-separacao", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['result' => 'ok', 'packed_at' => null]);
    }

    /**
     * Consulta de etiqueta do modal (2026-09-05). O que importa provar: a
     * consulta NUNCA cria PrintJob — foi criar job por gatilho de tela que
     * causou o incidente de reimpressão de 2026-08-12.
     *
     * Shein continua sendo o canal sem etiqueta nossa. O TikTok saiu dessa
     * lista em 2026-09-06: a etiqueta dele vem pelo Bling e passa pelo
     * fluxo normal, então aqui ele responde como qualquer outro canal.
     */
    public function test_label_status_for_shein_says_the_label_comes_from_the_channel(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_SHEIN);

        $this->getJson("/api/print-agent/dashboard/queue/{$order->id}/etiqueta-status", $this->authHeaders())
            ->assertOk()
            ->assertJson(['state' => 'channel_only']);

        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_label_status_for_tiktok_says_the_label_comes_from_the_channel(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        $this->getJson("/api/print-agent/dashboard/queue/{$order->id}/etiqueta-status", $this->authHeaders())
            ->assertOk()
            ->assertJson(['state' => 'channel_only']);

        $this->assertDatabaseCount('print_jobs', 0);
    }

    /**
     * A trava que o usuário pediu duas vezes: etiqueta do TikTok é do Bling
     * e sai no painel dele. Se sair pela nossa impressora, trava a
     * impressora.
     */
    public function test_reprint_is_refused_for_tiktok(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);
        $order->forceFill(['packed_at' => now()])->save();

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'tiktok_shop',
            'external_shipment_id' => 'SHIP-14',
            'shipping_method' => 'standard',
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'confirmed_at' => now(),
            'label_path' => "labels/{$order->id}/etiqueta-14.pdf",
            'label_ready_at' => now(),
        ]);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/reimprimir", [], $this->authHeaders())
            ->assertStatus(409)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_label_status_without_a_job_reports_pending_and_creates_nothing(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        $this->getJson("/api/print-agent/dashboard/queue/{$order->id}/etiqueta-status", $this->authHeaders())
            ->assertOk()
            ->assertJson(['state' => 'pending']);

        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_label_status_reports_a_job_already_queued_for_the_printer(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);
        PrintJob::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'status' => PrintJob::STATUS_QUEUED,
            'label_path' => 'labels/pedido-teste.pdf',
            'is_thank_you' => false,
        ]);

        $this->getJson("/api/print-agent/dashboard/queue/{$order->id}/etiqueta-status", $this->authHeaders())
            ->assertOk()
            ->assertJson(['state' => 'queued']);

        $this->assertDatabaseCount('print_jobs', 1);
    }

    public function test_label_status_reports_an_already_printed_label(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);
        PrintJob::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'status' => PrintJob::STATUS_PRINTED,
            'printed_at' => now(),
            'label_path' => 'labels/pedido-teste.pdf',
            'is_thank_you' => false,
        ]);

        $this->getJson("/api/print-agent/dashboard/queue/{$order->id}/etiqueta-status", $this->authHeaders())
            ->assertOk()
            ->assertJson(['state' => 'printed']);
    }
}

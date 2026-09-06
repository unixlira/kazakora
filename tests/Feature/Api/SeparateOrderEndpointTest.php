<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Modules\Marketplace\Support\SeparationGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Fase 3 (2026-09-04) — o clique de separar deixou de ser cego.
 *
 * O que importa provar aqui é a regra de segurança: pedido cancelado no
 * canal NÃO pode ser embalado, e o operador tem que receber o texto que diz
 * o que fazer com o produto que já está na mão dele.
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
     * TikTok entra pela ponte do Bling e a etiqueta continua saindo no
     * painel do TikTok — o clique só embala, sem consultar canal nenhum.
     */
    public function test_tiktok_order_is_packed_without_any_channel_check(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldNotReceive('import');
        $this->app->instance(OrderImportService::class, $importer);

        $response = $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders());

        $response->assertOk()
            ->assertJson(['result' => 'ok', 'channel_checked' => false]);

        $this->assertNotNull($order->refresh()->packed_at);
    }

    /**
     * O caso que a Fase 3 existe pra cobrir: a venda caiu no marketplace
     * DEPOIS de já estar na fila. Não embala, e devolve a mensagem do modal.
     */
    public function test_order_cancelled_at_the_channel_is_not_packed_and_returns_the_modal_message(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        // A reconsulta no canal descobre o cancelamento — é o import real
        // que grava o status novo, então o mock reproduz esse efeito.
        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')
            ->once()
            ->andReturnUsing(function () use ($order) {
                $order->forceFill(['status' => Order::STATUS_CANCELLED])->save();

                return $order;
            });
        $this->app->instance(OrderImportService::class, $importer);

        $response = $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders());

        $response->assertOk()
            ->assertJson([
                'result' => 'cancelled',
                'message' => SeparationGateService::CANCELLED_MESSAGE,
            ]);

        $this->assertNull($order->refresh()->packed_at, 'Pedido cancelado não pode ser marcado como embalado.');
    }

    /**
     * Falha-aberto: canal fora do ar não pode travar o galpão — embala, mas
     * avisa que ninguém conferiu.
     *
     * Queue::fake() aqui não é decoração: sem ele o dispatch do empurrão de
     * etiqueta roda INLINE (QUEUE_CONNECTION=sync no phpunit.xml) e bate
     * numa conta de canal não conectada. Produção usa fila database, então
     * o dispatch nunca executa dentro da requisição — o fake reproduz o
     * comportamento real, não o esconde.
     */
    public function test_channel_failure_still_packs_but_warns_the_operator(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_SHOPEE);

        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')->once()->andThrow(new \RuntimeException('API fora'));
        $this->app->instance(OrderImportService::class, $importer);

        $response = $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders());

        $response->assertOk()->assertJson(['result' => 'ok', 'channel_checked' => false]);
        $this->assertNotNull($order->refresh()->packed_at);
        $this->assertStringContainsString('Não deu pra confirmar', $response->json('message'));
    }

    /**
     * Pedido ativo de canal com etiqueta de verdade: além de embalar, o
     * fluxo de etiqueta é acionado — "se estiver ativo, aí gera/busca a
     * etiqueta" do briefing.
     */
    public function test_active_marketplace_order_is_packed_and_kicks_the_label_flow(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);

        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')->once()->andReturn($order);
        $this->app->instance(OrderImportService::class, $importer);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['result' => 'ok', 'channel_checked' => true]);

        $this->assertNotNull($order->refresh()->packed_at);
        Queue::assertPushed(ConfirmChannelShippingJob::class);
    }

    /**
     * BUG REAL 2026-09-06 ("imprimiu a etiqueta da Gabriela da Shopee"): a
     * etiqueta passou a ser baixada sem imprimir (ver
     * LabelFetchService::queuePrint()), então é ESTE clique que tem que
     * mandar pra impressora a etiqueta que já estava pronta e guardada. Sem
     * isso, o pedido separaria e nada sairia na impressora nunca.
     */
    public function test_separation_prints_the_label_that_was_waiting_for_it(): void
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

        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')->once()->andReturn($order);
        $this->app->instance(OrderImportService::class, $importer);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['result' => 'ok', 'label_queued' => true]);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'status' => PrintJob::STATUS_QUEUED,
        ]);
    }

    /**
     * O contrário: pedido cancelado no canal não embala — e, por tabela,
     * não imprime. A etiqueta guardada continua guardada.
     */
    public function test_a_cancelled_order_never_reaches_the_printer(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_SHOPEE);

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'external_shipment_id' => 'SHIP-10',
            'shipping_method' => 'standard',
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'confirmed_at' => now(),
            'label_path' => "labels/{$order->id}/etiqueta-10.pdf",
            'label_ready_at' => now(),
        ]);

        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')->once()->andReturnUsing(function () use ($order) {
            $order->forceFill(['status' => Order::STATUS_CANCELLED])->save();

            return $order;
        });
        $this->app->instance(OrderImportService::class, $importer);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/separar", [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['result' => SeparationGateService::RESULT_CANCELLED]);

        $this->assertNull($order->refresh()->packed_at);
        $this->assertDatabaseCount('print_jobs', 0);
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

    public function test_reprint_is_refused_for_an_order_that_was_not_separated(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_SHOPEE);

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'external_shipment_id' => 'SHIP-13',
            'shipping_method' => 'standard',
            'status' => ChannelShipment::STATUS_LABEL_READY,
            'confirmed_at' => now(),
            'label_path' => "labels/{$order->id}/etiqueta-13.pdf",
            'label_ready_at' => now(),
        ]);

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/reimprimir", [], $this->authHeaders())
            ->assertStatus(409)
            ->assertJson(['ok' => false]);

        $this->assertDatabaseCount('print_jobs', 0);
    }

    /**
     * Desfazer (2026-09-05) — o botão da aba "Separados" do KoraSync.
     */
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

    public function test_label_status_for_tiktok_follows_the_normal_flow(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        $this->getJson("/api/print-agent/dashboard/queue/{$order->id}/etiqueta-status", $this->authHeaders())
            ->assertOk()
            ->assertJson(['state' => 'pending']);

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

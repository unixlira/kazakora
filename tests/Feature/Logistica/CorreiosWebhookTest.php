<?php

namespace Tests\Feature\Logistica;

use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\Rastreio;
use App\Modules\Marketplace\Models\RastreioEvento;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Webhook de entrega dos Correios (2026-10-19): a entrega conclui o pedido do
 * site (Entregue) e grava data, unidade e quem recebeu na linha do tempo do
 * pedido; a consulta agendada grava a mesma linha.
 */
class CorreiosWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 20:00:00', 'America/Sao_Paulo'));
        config([
            'services.correios.webhook_token' => 'segredo',
            'services.correios.rastreio_whatsapp' => true,
            'services.whatsapp.templates.pedido_entregue' => 'pedido_entregue',
        ]);
        $this->mock(CorreiosTokenService::class, fn ($m) => $m->shouldReceive('tokenForPrecoPrazo')->andReturn('tok'));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedido(string $codigo = 'AE018212819BR', array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_SHIPPED,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(11) 98888-7777', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));

        CorreiosPrePostagem::query()->forceCreate([
            'order_id' => $order->id, 'codigo_objeto' => $codigo, 'status' => CorreiosPrePostagem::STATUS_GERADA,
            'customer_name' => 'Maria', 'zip' => '03187040', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Mooca',
            'city' => 'São Paulo', 'state' => 'SP', 'service_code' => '03298', 'weight_grams' => 500, 'content_items' => '[]',
        ]);

        return $order;
    }

    private static function eventoEntrega(array $extra = []): array
    {
        return array_merge([
            'codigo' => 'BDE', 'tipo' => '01', 'dtHrCriado' => '2026-10-15T19:26:47',
            'descricao' => 'Objeto entregue ao destinatário',
            'unidade' => ['nome' => 'CDD Mooca', 'tipo' => 'Unidade de Distribuição', 'endereco' => ['cidade' => 'SAO PAULO', 'uf' => 'SP']],
            'recebedor' => ['nome' => 'JOSE PORTEIRO', 'documento' => '123.456.789-01', 'celular' => '11999998888', 'comentario' => 'Porteiro'],
            'latitude' => '-23.5',
        ], $extra);
    }

    private function entregaDoPedido(Order $order): ?OrderFulfillmentEvent
    {
        return OrderFulfillmentEvent::query()->where('order_id', $order->id)->where('step', OrderFulfillmentEvent::STEP_DELIVERED)->first();
    }

    public function test_sem_token_ou_token_errado_recusa(): void
    {
        $order = $this->pedido();
        $corpo = ['codObjeto' => 'AE018212819BR', 'evento' => self::eventoEntrega()];

        $this->postJson('/api/correios/webhook', $corpo)->assertStatus(401);
        $this->postJson('/api/correios/webhook', $corpo, ['Authorization' => 'Bearer errado'])->assertStatus(401);
        config(['services.correios.webhook_token' => null]);
        $this->postJson('/api/correios/webhook', $corpo, ['Authorization' => 'Bearer '])->assertStatus(401);

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    public function test_entrega_pelo_webhook_vira_entregue_e_grava_quem_recebeu_no_historico(): void
    {
        $order = $this->pedido();

        $this->postJson('/api/correios/webhook', ['codObjeto' => 'AE018212819BR', 'evento' => self::eventoEntrega()], ['Authorization' => 'Bearer segredo'])
            ->assertOk()
            ->assertJsonPath('objetos.AE018212819BR', Order::STATUS_COMPLETED);

        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame('2026-10-15 19:26:47', $order->entregue_em->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));

        $evento = $this->entregaDoPedido($order);
        $this->assertSame(OrderFulfillmentEvent::STATUS_SUCCESS, $evento->status);
        $this->assertSame('Entregue em 15/10/2026 às 19:26 · Objeto entregue ao destinatário · CDD Mooca, SAO PAULO/SP · Quem recebeu: Jose Porteiro (doc. ***8901) (Porteiro) · Objeto AE018212819BR', $evento->message);
        $this->assertSame('webhook', $evento->context['origem']);
        $this->assertSame('BDE/01', $evento->context['evento']);
        // Celular e coordenadas não são gravados; documento só mascarado.
        $this->assertStringNotContainsString('11999998888', json_encode($evento->context));
        $this->assertStringNotContainsString('-23.5', json_encode($evento->context));
        $this->assertStringNotContainsString('123.456', json_encode($evento->context));

        $this->assertSame('entregue', Rastreio::query()->where('codigo', 'AE018212819BR')->value('situacao'));
        $this->assertSame(1, RastreioEvento::query()->count());
        Queue::assertPushed(SendOrderDeliveredWhatsAppJob::class, fn ($job) => true);
    }

    public function test_webhook_repetido_nao_duplica_historico_nem_aviso(): void
    {
        $order = $this->pedido();
        $corpo = ['objetos' => [['codObjeto' => 'AE018212819BR', 'eventos' => [self::eventoEntrega()]]]];

        $this->postJson('/api/correios/webhook', $corpo, ['Authorization' => 'Bearer segredo'])->assertOk();
        $order->forceFill(['whatsapp_entregue_em' => now()])->save();
        $this->postJson('/api/correios/webhook', $corpo, ['X-Webhook-Token' => 'segredo'])->assertOk();

        $this->assertSame(1, OrderFulfillmentEvent::query()->where('order_id', $order->id)->where('step', OrderFulfillmentEvent::STEP_DELIVERED)->count());
        $this->assertSame(1, RastreioEvento::query()->count());
        Queue::assertPushed(SendOrderDeliveredWhatsAppJob::class, 1);
    }

    public function test_sem_recebedor_informa_que_os_correios_nao_disseram(): void
    {
        $order = $this->pedido();
        $evento = self::eventoEntrega();
        unset($evento['recebedor']);

        $this->postJson('/api/correios/webhook?token=segredo', ['codObjeto' => 'AE018212819BR'] + $evento)->assertOk();

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertStringContainsString('Quem recebeu: não informado pelos Correios', $this->entregaDoPedido($order)->message);
    }

    public function test_so_o_codigo_consulta_o_sro_na_hora(): void
    {
        $order = $this->pedido();
        Http::fake(['api.correios.com.br/*' => Http::response(['objetos' => [['codObjeto' => 'AE018212819BR', 'eventos' => [
            self::eventoEntrega(),
            ['codigo' => 'PO', 'tipo' => '01', 'dtHrCriado' => '2026-10-13T14:08:48', 'descricao' => 'Objeto postado'],
        ]]]])]);

        $this->postJson('/api/correios/webhook', ['codObjeto' => 'AE018212819BR'], ['Authorization' => 'Bearer segredo'])->assertOk();

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(2, RastreioEvento::query()->count());
    }

    public function test_devolucao_ao_remetente_nao_conclui_e_fica_no_historico_como_falha(): void
    {
        $order = $this->pedido('AE018212819BR', ['shipping_city' => 'Petrópolis', 'shipping_state' => 'RJ']);

        $this->postJson('/api/correios/webhook', ['codObjeto' => 'AE018212819BR', 'evento' => self::eventoEntrega()], ['Authorization' => 'Bearer segredo'])->assertOk();

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $evento = $this->entregaDoPedido($order);
        $this->assertSame(OrderFulfillmentEvent::STATUS_FAILED, $evento->status);
        $this->assertStringContainsString('devolução ao remetente', $evento->message);
        Queue::assertNotPushed(SendOrderDeliveredWhatsAppJob::class);
    }

    public function test_outro_canal_so_grava_historico_sem_mudar_status(): void
    {
        $order = $this->pedido('AE018212819BR', ['origin' => Order::ORIGIN_AMAZON, 'status' => Order::STATUS_PAID]);

        $this->postJson('/api/correios/webhook', ['codObjeto' => 'AE018212819BR', 'evento' => self::eventoEntrega()], ['Authorization' => 'Bearer segredo'])->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertNotNull($this->entregaDoPedido($order));
        Queue::assertNotPushed(SendOrderDeliveredWhatsAppJob::class);
    }

    public function test_objeto_desconhecido_e_ignorado_sem_erro(): void
    {
        $this->postJson('/api/correios/webhook', ['codObjeto' => 'AA123456789BR', 'evento' => self::eventoEntrega()], ['Authorization' => 'Bearer segredo'])
            ->assertOk()
            ->assertJsonPath('objetos.AA123456789BR', 'ignorado: objeto sem pedido');
        $this->postJson('/api/correios/webhook', ['codObjeto' => 'invalido'], ['Authorization' => 'Bearer segredo'])->assertStatus(422);
    }

    public function test_consulta_agendada_grava_a_mesma_linha_no_historico(): void
    {
        $order = $this->pedido();
        Http::fake(['api.correios.com.br/*' => Http::response(['objetos' => [['codObjeto' => 'AE018212819BR', 'eventos' => [self::eventoEntrega()]]]])]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        $this->postJson('/api/correios/webhook', ['codObjeto' => 'AE018212819BR', 'evento' => self::eventoEntrega()], ['Authorization' => 'Bearer segredo'])->assertOk();

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $eventos = OrderFulfillmentEvent::query()->where('order_id', $order->id)->where('step', OrderFulfillmentEvent::STEP_DELIVERED)->get();
        $this->assertCount(1, $eventos);
        $this->assertSame('consulta', $eventos[0]->context['origem']);
    }
}

<?php

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\WhatsApp\Jobs\ChamarAtendentePedido;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WhatsApp "Seu pedido saiu para entrega" (2026-10-17): rastreio dos Correios
 * por consulta marca o pedido do site como enviado/entregue e a Manuela avisa
 * uma vez, com previsão e os botões de rastreio e atendente.
 */
class PedidoEnviadoWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.templates.pedido_enviado' => 'pedido_enviado',
        ]);
        $this->mock(CorreiosTokenService::class, fn ($m) => $m->shouldReceive('tokenForPrecoPrazo')->andReturn('tok'));
    }

    private function pedido(array $extra = []): Order
    {
        return Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(11) 98888-7777', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));
    }

    private function comObjeto(Order $order): Order
    {
        CorreiosPrePostagem::query()->forceCreate([
            'order_id' => $order->id, 'codigo_objeto' => 'AE000000000BR', 'status' => 'emitida',
            'customer_name' => 'Maria', 'zip' => '03187040', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Mooca',
            'city' => 'São Paulo', 'state' => 'SP', 'service_code' => '03220', 'weight_grams' => 500, 'content_items' => '[]',
        ]);

        return $order;
    }

    private array $objeto = [];

    /** Resposta do rastreio dos Correios; pode mudar no meio do teste. */
    private function rastro(array $eventos, ?string $prevista = null): void
    {
        $this->objeto = ['codObjeto' => 'AE000000000BR', 'dtPrevista' => $prevista, 'eventos' => $eventos];
        Http::fake([
            'api.correios.com.br/*' => fn () => Http::response(['objetos' => [$this->objeto]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ENVIADO']]]),
        ]);
    }

    public function test_postagem_marca_enviado_e_avisa_uma_vez_com_previsao_e_botoes(): void
    {
        $order = $this->comObjeto($this->pedido());
        $this->rastro([
            ['codigo' => 'PO', 'tipo' => '01', 'descricao' => 'Objeto postado', 'dtHrCriado' => now()->subHour()->format('Y-m-d\TH:i:s')],
            ['codigo' => 'FC', 'tipo' => '82', 'descricao' => 'Etiqueta emitida', 'dtHrCriado' => now()->subDay()->format('Y-m-d\TH:i:s')],
        ], '2026-10-22T23:59:59');

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->whatsapp_enviado_em);
        $enviados = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))->values();
        $this->assertCount(1, $enviados);
        $c = $enviados[0][0]['template']['components'];
        $this->assertStringEndsWith('/images/whatsapp/pedido-enviado.jpg', $c[0]['parameters'][0]['image']['link']);
        $this->assertSame(['Maria', $order->numero, 'até 22/10 (quinta-feira)'], array_column($c[1]['parameters'], 'text'));
        $this->assertSame(['url', $order->trackingRef()], [$c[2]['sub_type'], $c[2]['parameters'][0]['text']]);
        $this->assertSame(['quick_reply', "pedido_enviado:atendente:{$order->id}"], [$c[3]['sub_type'], $c[3]['parameters'][0]['payload']]);
    }

    public function test_so_etiqueta_nao_muda_nada_e_entregue_conclui(): void
    {
        $order = $this->comObjeto($this->pedido());
        $this->rastro([['codigo' => 'FC', 'tipo' => '82', 'descricao' => 'Etiqueta emitida']]);
        $this->artisan('loja:acompanhar-rastreio');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        $this->objeto['eventos'] = [['codigo' => 'BDE', 'tipo' => '01', 'descricao' => 'Objeto entregue ao destinatário', 'dtHrCriado' => now()->format('Y-m-d\TH:i:s')]];
        $this->artisan('loja:acompanhar-rastreio');
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_sem_template_ou_marketplace_nao_envia_e_previsao_padrao(): void
    {
        Http::fake();
        SendOrderShippedWhatsAppJob::dispatchSync($this->pedido(['origin' => Order::ORIGIN_MERCADO_LIVRE])->id);
        config(['services.whatsapp.templates.pedido_enviado' => null]);
        SendOrderShippedWhatsAppJob::dispatchSync($this->pedido()->id);

        Http::assertNothingSent();
        $this->assertSame('nos próximos dias úteis', SendOrderShippedWhatsAppJob::previsao(null));
    }

    public function test_botao_falar_com_atendente_chama_humano(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.R']]])]);
        $order = $this->pedido();
        $conversa = WhatsAppConversation::query()->create(['wa_id' => '5511988887777', 'phone' => '5511988887777', 'unread_count' => 0]);

        ChamarAtendentePedido::dispatchSync($conversa->id, ChamarAtendentePedido::PAYLOAD.$order->id);
        ChamarAtendentePedido::dispatchSync($conversa->id, ChamarAtendentePedido::PAYLOAD.$order->id);

        $this->assertTrue($conversa->fresh()->needs_human);
        Http::assertSentCount(1);
    }
}

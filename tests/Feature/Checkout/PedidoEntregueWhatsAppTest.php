<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\WhatsApp\Jobs\ResponderNaoRecebi;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Notifications\NaoRecebiPedidoWhatsAppNotification;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * WhatsApp "Seu pedido foi entregue" (2026-10-17): entrega registrada pelos
 * Correios conclui o pedido do site e a Manuela avisa uma vez, com data,
 * quem recebeu e os botões Comprovante Entrega, Não Recebi e Falar com
 * Atendente.
 */
class PedidoEntregueWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.templates.pedido_entregue' => 'pedido_entregue',
        ]);
        $this->mock(CorreiosTokenService::class, fn ($m) => $m->shouldReceive('tokenForPrecoPrazo')->andReturn('tok'));
    }

    private function pedido(array $extra = []): Order
    {
        return Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_SHIPPED,
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

    private function rastro(array $entrega): void
    {
        Http::fake([
            'api.correios.com.br/*' => Http::response(['objetos' => [['codObjeto' => 'AE000000000BR', 'eventos' => [
                array_merge(['codigo' => 'BDE', 'tipo' => '01', 'descricao' => 'Objeto entregue ao destinatário'], $entrega),
                ['codigo' => 'PO', 'tipo' => '01', 'descricao' => 'Objeto postado', 'dtHrCriado' => now()->subDays(3)->format('Y-m-d\TH:i:s')],
            ]]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ENTREGUE']]]),
        ]);
    }

    private function enviados()
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))->values();
    }

    public function test_entrega_conclui_e_avisa_uma_vez_com_data_recebedor_e_tres_botoes(): void
    {
        $order = $this->comObjeto($this->pedido());
        $this->rastro(['dtHrCriado' => now()->setTime(12, 54)->format('Y-m-d\TH:i:s')]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        SendOrderDeliveredWhatsAppJob::dispatchSync($order->id);

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->whatsapp_entregue_em);
        $this->assertCount(1, $this->enviados());
        $c = $this->enviados()[0][0]['template']['components'];
        $this->assertStringEndsWith('/images/whatsapp/pedido-entregue.jpg', $c[0]['parameters'][0]['image']['link']);
        $this->assertSame(['Maria', $order->numero, now()->format('d/m').' às 12:54', 'não informado pelos Correios'], array_column($c[1]['parameters'], 'text'));
        $this->assertSame(['url', $order->trackingRef()], [$c[2]['sub_type'], $c[2]['parameters'][0]['text']]);
        $this->assertSame(['quick_reply', "pedido_entregue:nao_recebi:{$order->id}"], [$c[3]['sub_type'], $c[3]['parameters'][0]['payload']]);
        $this->assertSame(['quick_reply', "pedido_enviado:atendente:{$order->id}"], [$c[4]['sub_type'], $c[4]['parameters'][0]['payload']]);
    }

    public function test_recebedor_dos_correios_vai_na_mensagem(): void
    {
        $order = $this->comObjeto($this->pedido());
        $this->rastro(['dtHrCriado' => now()->format('Y-m-d\TH:i:s'), 'recebedor' => ['nome' => 'JOSE PORTEIRO']]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $this->assertSame('Jose Porteiro', $this->enviados()[0][0]['template']['components'][1]['parameters'][3]['text']);
    }

    public function test_entrega_antiga_conclui_sem_avisar_e_marketplace_nao_recebe(): void
    {
        $order = $this->comObjeto($this->pedido());
        $this->rastro(['dtHrCriado' => now()->subDays(5)->format('Y-m-d\TH:i:s')]);
        $this->artisan('loja:acompanhar-rastreio');
        SendOrderDeliveredWhatsAppJob::dispatchSync($this->pedido(['origin' => Order::ORIGIN_MERCADO_LIVRE])->id);

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertCount(0, $this->enviados());
        $this->assertSame('hoje', SendOrderDeliveredWhatsAppJob::quando(null));
    }

    public function test_botao_nao_recebi_marca_pedido_chama_humano_e_avisa_admins(): void
    {
        Notification::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.R']]])]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->pedido(['status' => Order::STATUS_COMPLETED]);
        $conversa = WhatsAppConversation::query()->create(['wa_id' => '5511988887777', 'phone' => '5511988887777', 'unread_count' => 0]);

        ResponderNaoRecebi::dispatchSync($conversa->id, ResponderNaoRecebi::PAYLOAD.$order->id);
        ResponderNaoRecebi::dispatchSync($conversa->id, ResponderNaoRecebi::PAYLOAD.$order->id);

        $this->assertNotNull($order->fresh()->whatsapp_nao_recebi_em);
        $this->assertTrue($conversa->fresh()->needs_human);
        Http::assertSentCount(1);
        Notification::assertSentToTimes($admin, NaoRecebiPedidoWhatsAppNotification::class, 1);
    }

    public function test_botao_nao_recebi_de_outro_numero_e_ignorado(): void
    {
        Http::fake();
        $order = $this->pedido();
        $conversa = WhatsAppConversation::query()->create(['wa_id' => '5521977776666', 'phone' => '5521977776666', 'unread_count' => 0]);

        ResponderNaoRecebi::dispatchSync($conversa->id, ResponderNaoRecebi::PAYLOAD.$order->id);

        $this->assertNull($order->fresh()->whatsapp_nao_recebi_em);
        Http::assertNothingSent();
    }
}

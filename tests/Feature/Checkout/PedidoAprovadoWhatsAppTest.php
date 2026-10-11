<?php

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Jobs\SendOrderApprovedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp de pedido aprovado (pedido 2026-10-10): template da Meta com nome,
 * número do pedido e botão "Acessar rastreio" — uma vez só por pedido.
 */
class PedidoAprovadoWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.templates.pedido_aprovado' => 'pedido_aprovado',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.APROVADO']]])]);
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

    public function test_envia_template_com_nome_pedido_e_link_de_rastreio_uma_vez(): void
    {
        $order = $this->pedido();

        SendOrderApprovedWhatsAppJob::dispatchSync($order->id);
        SendOrderApprovedWhatsAppJob::dispatchSync($order->id);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($order) {
            $template = $request['template'];

            return $request['to'] === '5511988887777'
                && $template['name'] === 'pedido_aprovado'
                && $template['components'][0]['parameters'][0]['text'] === 'Maria'
                && $template['components'][0]['parameters'][1]['text'] === (string) $order->id
                && $template['components'][1]['sub_type'] === 'url'
                && $template['components'][1]['parameters'][0]['text'] === $order->trackingRef();
        });
        $this->assertNotNull($order->fresh()->whatsapp_aprovado_enviado_em);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'template')->count());
    }

    public function test_nao_envia_sem_template_ou_para_marketplace(): void
    {
        $marketplace = $this->pedido(['origin' => Order::ORIGIN_MERCADO_LIVRE]);
        SendOrderApprovedWhatsAppJob::dispatchSync($marketplace->id);

        config(['services.whatsapp.templates.pedido_aprovado' => null]);
        SendOrderApprovedWhatsAppJob::dispatchSync($this->pedido()->id);

        Http::assertNothingSent();
    }
}

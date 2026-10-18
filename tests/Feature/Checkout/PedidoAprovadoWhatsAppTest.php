<?php

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Jobs\SendOrderApprovedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\WhatsApp\Jobs\FaturarPedidoSemResposta;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp de pedido aprovado (2026-10-17): template da Meta com os dados do
 * pedido para conferência e os botões "Tudo Certo" e "Corrigir Informações",
 * uma vez só por pedido.
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
        Bus::fake([GenerateInvoiceJob::class, FaturarPedidoSemResposta::class]);
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

    public function test_envia_conferencia_de_dados_com_botoes_uma_vez(): void
    {
        $order = $this->pedido(['shipping_email' => 'maria@exemplo.com', 'shipping_complement' => 'Apto 3']);

        SendOrderApprovedWhatsAppJob::dispatchSync($order->id);
        SendOrderApprovedWhatsAppJob::dispatchSync($order->id);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($order) {
            $template = $request['template'];
            $c = $template['components'];
            $corpo = array_column($c[1]['parameters'], 'text');

            return $request['to'] === '5511988887777'
                && $template['name'] === 'pedido_aprovado'
                && $c[0]['type'] === 'header'
                && str_ends_with($c[0]['parameters'][0]['image']['link'], '/images/whatsapp/confirmacao-pedido.jpg')
                && $corpo === ['Maria', $order->numero, 'Maria da Silva', 'maria@exemplo.com', 'Rua X, 1 - Apto 3 - Mooca - 03187-040 - São Paulo/SP']
                && $c[2]['sub_type'] === 'quick_reply' && $c[2]['parameters'][0]['payload'] === "confirmacao_pedido:ok:{$order->id}"
                && $c[3]['sub_type'] === 'quick_reply' && $c[3]['parameters'][0]['payload'] === "confirmacao_pedido:corrigir:{$order->id}";
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

    public function test_template_nao_aprovado_desiste_na_hora_e_emite_a_nota(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'message' => '(#132001) Template name does not exist in the translation', 'code' => 132001,
        ]], 404)]);
        $order = $this->pedido();

        SendOrderApprovedWhatsAppJob::dispatchSync($order->id);

        Http::assertSentCount(1);
        Bus::assertDispatched(GenerateInvoiceJob::class);
        Bus::assertNotDispatched(FaturarPedidoSemResposta::class);
        $this->assertNull($order->fresh()->whatsapp_aprovado_enviado_em);
    }

    public function test_falha_passageira_continua_tentando(): void
    {
        Http::swap(new HttpFactory);
        Http::swap(new HttpFactory);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503)]);
        $order = $this->pedido();

        try {
            SendOrderApprovedWhatsAppJob::dispatchSync($order->id);
            $this->fail('Falha passageira deveria voltar para a fila.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Falha ao enviar WhatsApp', $e->getMessage());
        }

        $this->assertFalse(SendOrderApprovedWhatsAppJob::erroDefinitivo($e));
    }
}

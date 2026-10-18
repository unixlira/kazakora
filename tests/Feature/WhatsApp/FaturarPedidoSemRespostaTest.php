<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\Checkout\Jobs\SendOrderApprovedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\WhatsApp\Jobs\FaturarPedidoSemResposta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * NF-e do pedido do site com a confirmação pelo WhatsApp (2026-10-17): sai
 * no "Tudo Certo" ou 30 min depois da mensagem; "Corrigir" segura a nota.
 */
class FaturarPedidoSemRespostaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.templates.pedido_aprovado' => 'pedido_aprovado',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
        Bus::fake([GenerateInvoiceJob::class, FaturarPedidoSemResposta::class]);
    }

    private function pedido(array $extra = [], ?string $confirmacao = null): Order
    {
        return tap(Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(11) 98888-7777', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra)), fn (Order $order) => $order->forceFill(['whatsapp_confirmacao' => $confirmacao])->save());
    }

    public function test_mensagem_enviada_agenda_a_nota_para_30_minutos(): void
    {
        $order = $this->pedido();

        $this->assertTrue(SendOrderApprovedWhatsAppJob::seguraNota($order));
        SendOrderApprovedWhatsAppJob::dispatchSync($order->id);

        Bus::assertNotDispatched(GenerateInvoiceJob::class);
        Bus::assertDispatched(FaturarPedidoSemResposta::class, fn ($job) => $job->orderId === $order->id
            && $job->delay->diffInMinutes(now(), true) >= 29);
    }

    public function test_sem_template_a_nota_nao_espera(): void
    {
        config(['services.whatsapp.templates.pedido_aprovado' => null]);

        $this->assertFalse(SendOrderApprovedWhatsAppJob::seguraNota($this->pedido()));
        $this->assertFalse(SendOrderApprovedWhatsAppJob::seguraNota($this->pedido(['origin' => Order::ORIGIN_MERCADO_LIVRE])));
    }

    public function test_sem_telefone_ou_falha_esgotada_emite_na_hora(): void
    {
        $semTelefone = $this->pedido(['shipping_phone' => '']);
        SendOrderApprovedWhatsAppJob::dispatchSync($semTelefone->id);
        Bus::assertDispatched(GenerateInvoiceJob::class, fn ($job) => $job->orderId === $semTelefone->id);

        $falhou = $this->pedido();
        (new SendOrderApprovedWhatsAppJob($falhou->id))->failed(new RuntimeException('Meta fora'));
        Bus::assertDispatched(GenerateInvoiceJob::class, fn ($job) => $job->orderId === $falhou->id);
        Bus::assertNotDispatched(FaturarPedidoSemResposta::class);
    }

    public function test_prazo_vencido_emite_sem_resposta_ou_com_tudo_certo(): void
    {
        $semResposta = $this->pedido();
        $tudoCerto = $this->pedido([], 'ok');

        (new FaturarPedidoSemResposta($semResposta->id))->handle();
        (new FaturarPedidoSemResposta($tudoCerto->id))->handle();

        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 2);
    }

    public function test_corrigir_ou_nota_existente_nao_emite(): void
    {
        $corrigir = $this->pedido([], 'corrigir');
        $comNota = $this->pedido();
        Invoice::create(['order_id' => $comNota->id, 'serie' => 2, 'numero' => 3001, 'status' => Invoice::STATUS_AUTHORIZED, 'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO]);

        (new FaturarPedidoSemResposta($corrigir->id))->handle();
        (new FaturarPedidoSemResposta($comNota->id))->handle();

        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }
}

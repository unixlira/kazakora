<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Notifications\CorrecaoPedidoWhatsAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Botões "Tudo Certo" e "Corrigir Informações" da confirmação do pedido
 * (2026-10-17): voltam pelo webhook como mensagem do tipo "button".
 */
class ConfirmacaoPedidoBotoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.app_secret' => null,
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RESPOSTA']]])]);
    }

    private function pedido(): Order
    {
        return Order::create([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(11) 98888-7777', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);
    }

    private function toque(string $de, string $payload, string $texto, string $id): void
    {
        $this->postJson('/api/webhooks/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Maria']]],
            'messages' => [[
                'from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'button',
                'button' => ['payload' => $payload, 'text' => $texto],
            ]],
        ]]]]]])->assertOk();
    }

    public function test_tudo_certo_grava_no_pedido_e_responde_uma_vez(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $order = $this->pedido();

        $this->toque('5511988887777', "confirmacao_pedido:ok:{$order->id}", 'Tudo Certo', 'wamid.A');
        $this->toque('5511988887777', "confirmacao_pedido:ok:{$order->id}", 'Tudo Certo', 'wamid.B');

        $this->assertSame('ok', $order->fresh()->whatsapp_confirmacao);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text']['body'], "Perfeito, Maria! Seu pedido #{$order->numero}"));
        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
    }

    public function test_corrigir_chama_humano_e_avisa_admin_sem_passar_pela_ia(): void
    {
        Notification::fake();
        Bus::fake([ReplyWithManuela::class, GenerateInvoiceJob::class]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->pedido();

        $this->toque('5511988887777', "confirmacao_pedido:corrigir:{$order->id}", 'Corrigir Informações', 'wamid.C');

        $this->assertSame('corrigir', $order->fresh()->whatsapp_confirmacao);
        $conversa = WhatsAppConversation::query()->where('wa_id', '5511988887777')->first();
        $this->assertTrue($conversa->needs_human);
        Http::assertSent(fn ($r) => str_contains($r['text']['body'], 'o que precisa corrigir'));
        Notification::assertSentTo($admin, CorrecaoPedidoWhatsAppNotification::class);
        Bus::assertNotDispatched(ReplyWithManuela::class);
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_botao_de_outro_numero_e_ignorado(): void
    {
        $order = $this->pedido();

        $this->toque('5521977776666', "confirmacao_pedido:corrigir:{$order->id}", 'Corrigir Informações', 'wamid.D');

        $this->assertNull($order->fresh()->whatsapp_confirmacao);
        Http::assertNothingSent();
        $this->assertSame(1, WhatsAppMessage::query()->where('direction', 'inbound')->count());
    }

    /** Achado real 19/10, #3274: a Meta mandou o toque sem o nono dígito. */
    public function test_tudo_certo_vindo_sem_o_nono_digito_vale(): void
    {
        Bus::fake([GenerateInvoiceJob::class]);
        $order = $this->pedido();

        $this->toque('551188887777', "confirmacao_pedido:ok:{$order->id}", 'Tudo Certo', 'wamid.E');

        $this->assertSame('ok', $order->fresh()->whatsapp_confirmacao);
        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
    }

    public function test_sem_nono_digito_de_outro_numero_continua_ignorado(): void
    {
        $order = $this->pedido();

        $this->toque('551188887766', "confirmacao_pedido:ok:{$order->id}", 'Tudo Certo', 'wamid.F');

        $this->assertNull($order->fresh()->whatsapp_confirmacao);
        Http::assertNothingSent();
    }

    /**
     * Achado real 19/10, #3274: confirmação foi para o número com o 9 e o
     * toque voltou sem o 9, abrindo outra conversa sem o histórico.
     */
    public function test_toque_sem_nono_digito_cai_na_conversa_da_confirmacao(): void
    {
        Notification::fake();
        Bus::fake([GenerateInvoiceJob::class]);
        $order = $this->pedido();
        $conversa = WhatsAppConversation::create(['wa_id' => '5511988887777', 'phone' => '5511988887777', 'last_message_at' => now()]);

        $this->toque('551188887777', "confirmacao_pedido:corrigir:{$order->id}", 'Corrigir Informações', 'wamid.G');

        $this->assertSame(1, WhatsAppConversation::query()->count());
        $this->assertSame(1, $conversa->messages()->where('direction', 'inbound')->count());
        $this->assertSame('corrigir', $order->fresh()->whatsapp_confirmacao);
    }

    public function test_corrigir_depois_da_nota_nao_promete_ajustar_antes_do_faturamento(): void
    {
        Notification::fake();
        $order = $this->pedido();
        Invoice::create(['order_id' => $order->id, 'serie' => 2, 'numero' => 3001, 'status' => Invoice::STATUS_AUTHORIZED, 'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO]);

        $this->toque('5511988887777', "confirmacao_pedido:corrigir:{$order->id}", 'Corrigir Informações', 'wamid.H');

        Http::assertSent(fn ($r) => str_contains($r['text']['body'], 'já foram geradas'));
        Http::assertNotSent(fn ($r) => str_contains($r['text']['body'] ?? '', 'antes do faturamento'));
    }

    public function test_botao_que_o_sistema_nao_trata_passa_para_a_manuela(): void
    {
        Bus::fake([ReplyWithManuela::class]);
        $order = $this->pedido();
        WhatsAppConversation::create(['wa_id' => '5521977776666', 'phone' => '5521977776666', 'ai_enabled' => true]);

        $this->toque('5521977776666', "confirmacao_pedido:ok:{$order->id}", 'Tudo Certo', 'wamid.I');

        Bus::assertDispatchedSync(ReplyWithManuela::class);
        $this->assertNull($order->fresh()->whatsapp_confirmacao);
    }

    public function test_manuela_ve_o_aviso_como_da_loja_e_o_pedido_da_conversa(): void
    {
        $order = $this->pedido();
        $conversa = WhatsAppConversation::create(['wa_id' => '5511988887777', 'phone' => '5511988887777']);
        WhatsAppMessage::create(['conversation_id' => $conversa->id, 'direction' => 'outbound', 'type' => 'template', 'status' => 'read', 'sent_by' => 'system',
            'body' => "Pedido #{$order->numero} confirmado. [Tudo Certo] [Corrigir Informações]", 'payload' => ['order_id' => $order->id]]);

        $pedidos = ManuelaAutoReplyService::pedidosDaConversa($conversa);
        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt($conversa);

        $this->assertSame($order->numero, $pedidos[0]['numero']);
        $this->assertSame('ainda não respondeu', $pedidos[0]['confirmacao_do_cliente']);
        $this->assertStringContainsString('Rua X, 1', $pedidos[0]['endereco']);
        $this->assertStringContainsString('Corrigir Informações', $prompt);
        $this->assertStringContainsString($order->numero, $prompt);
    }
}

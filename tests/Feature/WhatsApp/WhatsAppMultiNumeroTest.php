<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderApprovedWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\WhatsApp\Jobs\FaturarPedidoSemResposta;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vários números no mesmo webhook (2026-10-22): KazaKora (id 123) e
 * FerraKora (id 456). A loja sai do metadata.phone_number_id e a conversa
 * responde sempre pelo número dela.
 */
class WhatsAppMultiNumeroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.numeros.ferrakora.phone_number_id' => '456',
            'services.whatsapp.numeros.ferrakora.access_token' => 'ferra-token',
            'services.whatsapp.manuela_url' => null,
            'services.gemini.api_key' => null,
            'services.gemini.chat_model' => 'gemini-test',
            'services.gemini.fallback_models' => [],
            'services.gemini.retry_delay_ms' => 0,
        ]);

        app(WhatsAppSettings::class)->setMany(['auto_reply_enabled' => false]);
        $n = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$n) {
            return Http::response(['messages' => [['id' => 'wamid.OUT'.(++$n)]]]);
        }]);
    }

    private function inbound(string $id, string $text, ?string $phoneNumberId, string $from = '5511999990000'): array
    {
        $value = [
            'messaging_product' => 'whatsapp',
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Cliente']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ];

        if ($phoneNumberId !== null) {
            $value['metadata'] = ['display_phone_number' => '5511900000000', 'phone_number_id' => $phoneNumberId];
        }

        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => $value]]]]];
    }

    private function graphPosts(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))->values()->all();
    }

    public function test_mesmo_cliente_nos_dois_numeros_vira_duas_conversas_e_cada_uma_responde_pelo_seu_numero(): void
    {
        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.K1', 'Oi Kaza', '123'))->assertOk();
        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.F1', 'Oi Ferra', '456'))->assertOk();

        $kaza = WhatsAppConversation::query()->where('loja', 'kazakora')->sole();
        $ferra = WhatsAppConversation::query()->where('loja', 'ferrakora')->sole();
        $this->assertSame('5511999990000', $kaza->wa_id);
        $this->assertSame('5511999990000', $ferra->wa_id);
        $this->assertNotSame($kaza->id, $ferra->id);
        $this->assertSame('Oi Ferra', $ferra->messages()->sole()->body);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->postJson("/admin/whatsapp/conversas/{$ferra->id}/mensagens", ['body' => 'Resposta Ferra'])->assertOk();
        $this->actingAs($admin)->postJson("/admin/whatsapp/conversas/{$kaza->id}/mensagens", ['body' => 'Resposta Kaza'])->assertOk();

        [$primeiro, $segundo] = $this->graphPosts();
        $this->assertStringEndsWith('/456/messages', $primeiro[0]->url());
        $this->assertTrue($primeiro[0]->hasHeader('Authorization', 'Bearer ferra-token'));
        $this->assertSame('Resposta Ferra', $primeiro[0]['text']['body']);
        $this->assertStringEndsWith('/123/messages', $segundo[0]->url());
        $this->assertTrue($segundo[0]->hasHeader('Authorization', 'Bearer meta-token'));
        $this->assertSame('Resposta Kaza', $segundo[0]['text']['body']);
    }

    public function test_inbox_mostra_a_loja_da_conversa(): void
    {
        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.F2', 'Oi', '456'))->assertOk();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/admin/whatsapp/conversas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('conversations.0.loja', 'ferrakora')->where('conversations.0.lojaNome', 'FerraKora')->etc());
    }

    public function test_numero_desconhecido_e_ignorado_sem_erro(): void
    {
        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.X1', 'Oi', '999'))
            ->assertOk()
            ->assertJsonPath('handled', 0);

        $this->assertSame(0, WhatsAppConversation::query()->count());
    }

    public function test_kazakora_sem_metadata_continua_igual(): void
    {
        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.K2', 'Oi', null))->assertOk();

        $conversa = WhatsAppConversation::query()->sole();
        $this->assertSame('kazakora', $conversa->loja);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->postJson("/admin/whatsapp/conversas/{$conversa->id}/mensagens", ['body' => 'Olá'])->assertOk();

        $this->assertStringEndsWith('/123/messages', $this->graphPosts()[0][0]->url());
    }

    public function test_ferrakora_sem_numero_configurado_e_ignorada_e_kazakora_segue(): void
    {
        config(['services.whatsapp.numeros.ferrakora.phone_number_id' => null]);

        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.F3', 'Oi', '456'))->assertOk()->assertJsonPath('handled', 0);
        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.K3', 'Oi', '123'))->assertOk()->assertJsonPath('handled', 1);

        $this->assertSame(['kazakora'], WhatsAppConversation::query()->pluck('loja')->all());
        $this->assertFalse(app(WhatsAppSettings::class)->isReadyToSend('ferrakora'));
        $this->assertTrue(app(WhatsAppSettings::class)->isReadyToSend());
    }

    public function test_status_usa_a_loja_do_numero(): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '456'],
            'statuses' => [['id' => 'wamid.S1', 'status' => 'delivered', 'recipient_id' => '5511977776666']],
        ]]]]]];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertOk()->assertJsonPath('handled', 1);

        $conversa = WhatsAppConversation::query()->sole();
        $this->assertSame(['5511977776666', 'ferrakora'], [$conversa->wa_id, $conversa->loja]);
        $this->assertSame('delivered', WhatsAppMessage::query()->sole()->status);
    }

    public function test_cliente_paraLoja_monta_a_url_do_numero_certo(): void
    {
        $client = app(WhatsAppCloudApiClient::class);
        $client->sendText('5511900000001', 'a');
        $client->paraLoja('ferrakora')->sendText('5511900000001', 'b');

        $posts = $this->graphPosts();
        $this->assertStringEndsWith('/123/messages', $posts[0][0]->url());
        $this->assertStringEndsWith('/456/messages', $posts[1][0]->url());
    }

    public function test_manuela_da_ferrakora_usa_nome_e_site_da_ferrakora_sem_citar_kazakora(): void
    {
        config(['services.gemini.api_key' => 'gemini-key']);
        app(WhatsAppSettings::class)->setMany(['enabled' => true, 'auto_reply_enabled' => true]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Oi! Eu sou a Manuela do FerraKora. Como posso te ajudar hoje?']]]]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]]),
        ]);

        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.F4', 'Oi, tem furadeira?', '456'))->assertOk();

        $gemini = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'generativelanguage'))->values();
        $this->assertCount(1, $gemini);
        $prompt = $gemini[0][0]['systemInstruction']['parts'][0]['text'];
        $this->assertStringContainsString('FerraKora', $prompt);
        $this->assertStringContainsString('https://ferrakora.com', $prompt);
        $this->assertStringContainsString('Não dá desconto, cupom', $prompt);
        $this->assertStringNotContainsString('KazaKora', str_replace('nem a KazaKora', '', $prompt));
        $this->assertArrayNotHasKey('tools', $gemini[0][0]->data());

        $this->assertStringEndsWith('/456/messages', $this->graphPosts()[0][0]->url());
    }

    public function test_procon_na_ferrakora_para_o_bot_e_chama_humano(): void
    {
        config(['services.gemini.api_key' => 'gemini-key']);
        app(WhatsAppSettings::class)->setMany(['enabled' => true, 'auto_reply_enabled' => true]);

        $this->postJson('/api/webhooks/whatsapp', $this->inbound('wamid.F5', 'Isso é golpe, vou no Procon', '456'))->assertOk();

        $conversa = WhatsAppConversation::query()->sole();
        $this->assertFalse($conversa->ai_enabled);
        $this->assertTrue($conversa->needs_human);
        $this->assertSame(ManuelaAutoReplyService::FERRAKORA_PARAR_BOT_REPLY, $conversa->messages()->where('direction', 'outbound')->value('body'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'generativelanguage'));
        $this->assertStringEndsWith('/456/messages', $this->graphPosts()[0][0]->url());
    }

    public function test_procon_na_kazakora_nao_muda(): void
    {
        $conversa = WhatsAppConversation::query()->create(['wa_id' => '5511999990000', 'phone' => '5511999990000']);

        $reply = app(ManuelaAutoReplyService::class)->buildReply($conversa, 'vou no procon');

        $this->assertArrayNotHasKey('stop_bot', $reply);
        $this->assertTrue($reply['needs_human']);
    }

    private function pedido(array $extra = []): Order
    {
        $order = Order::create([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_SHIPPED,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(11) 98888-7777', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);
        $order->forceFill($extra)->save();

        return $order;
    }

    private function enviarAviso(Order $order): void
    {
        (new SendOrderShippedWhatsAppJob($order->id))->handle(app(WhatsAppCloudApiClient::class), app(WhatsAppSettings::class));
    }

    public function test_aviso_de_pedido_da_ferrakora_sai_pelo_numero_dela_com_o_template_dela(): void
    {
        config([
            'services.whatsapp.templates.pedido_enviado' => 'pedido_enviado_kaza',
            'services.whatsapp.numeros.ferrakora.templates.pedido_enviado' => 'pedido_enviado_ferra',
        ]);
        $ferra = $this->pedido(['site_origem' => Order::SITE_FERRAKORA]);
        $kaza = $this->pedido();

        $this->enviarAviso($ferra);
        $this->enviarAviso($kaza);

        [$primeiro, $segundo] = $this->graphPosts();
        $this->assertStringEndsWith('/456/messages', $primeiro[0]->url());
        $this->assertSame('pedido_enviado_ferra', $primeiro[0]['template']['name']);
        $this->assertStringEndsWith('/123/messages', $segundo[0]->url());
        $this->assertSame('pedido_enviado_kaza', $segundo[0]['template']['name']);
        $this->assertSame('ferrakora', WhatsAppConversation::query()->where('wa_id', '5511988887777')->orderBy('id')->value('loja'));
    }

    public function test_aviso_de_pedido_da_ferrakora_sem_numero_dela_segue_pela_kazakora(): void
    {
        config([
            'services.whatsapp.templates.pedido_enviado' => 'pedido_enviado_kaza',
            'services.whatsapp.numeros.ferrakora.phone_number_id' => null,
        ]);

        $this->enviarAviso($this->pedido(['site_origem' => Order::SITE_FERRAKORA]));

        $post = $this->graphPosts()[0][0];
        $this->assertStringEndsWith('/123/messages', $post->url());
        $this->assertSame('pedido_enviado_kaza', $post['template']['name']);
    }
    public function test_boas_vindas_da_ferrakora_sai_com_o_template_e_o_banner_dela(): void
    {
        config([
            'services.whatsapp.templates.pedido_aprovado' => 'confirmacao_kaza',
            'services.whatsapp.numeros.ferrakora.templates.pedido_aprovado' => 'confirmacao_pedido_ferrakora',
        ]);
        Bus::fake([GenerateInvoiceJob::class, FaturarPedidoSemResposta::class]);
        $ferra = $this->pedido(['site_origem' => Order::SITE_FERRAKORA, 'status' => Order::STATUS_PAID]);
        $kaza = $this->pedido(['status' => Order::STATUS_PAID]);

        foreach ([$ferra, $kaza] as $order) {
            (new SendOrderApprovedWhatsAppJob($order->id))->handle(app(WhatsAppCloudApiClient::class), app(WhatsAppSettings::class));
        }

        [$primeiro, $segundo] = $this->graphPosts();
        $this->assertStringEndsWith('/456/messages', $primeiro[0]->url());
        $this->assertSame('confirmacao_pedido_ferrakora', $primeiro[0]['template']['name']);
        $this->assertStringEndsWith('/images/whatsapp/confirmacao-pedido-ferrakora.jpg', $primeiro[0]['template']['components'][0]['parameters'][0]['image']['link']);
        $this->assertStringEndsWith('/images/whatsapp/confirmacao-pedido.jpg', $segundo[0]['template']['components'][0]['parameters'][0]['image']['link']);
    }
}

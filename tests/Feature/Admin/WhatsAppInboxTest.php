<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp > Conversas (2026-10-08): webhook alimenta a lista (prévia, não
 * lidas), a Manuela da Naia responde pelo Hermes depois do 200 pra Meta, e
 * atendente humano que responde tira a Manuela da conversa.
 */
class WhatsAppInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.manuela_url' => 'https://alphakora.test/v1',
            'services.whatsapp.manuela_token' => 'hermes-key',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function inbound(string $id, string $text, string $from = '5511999990000'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Maria Souza']]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $id,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function enableManuela(): void
    {
        app(WhatsAppSettings::class)->setMany(['enabled' => true, 'auto_reply_enabled' => true]);
    }

    public function test_mensagem_recebida_entra_na_lista_e_a_manuela_responde_pelo_hermes(): void
    {
        $this->enableManuela();
        Http::fake([
            'alphakora.test/*' => Http::response(['choices' => [['message' => ['content' => 'Oi Maria! Qual modelo você procura?']]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.IN1', 'Oi, tem campainha?'))->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertSame('Maria Souza', $conversation->profile_name);
        $this->assertSame(1, $conversation->unread_count);

        $reply = WhatsAppMessage::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame('Oi Maria! Qual modelo você procura?', $reply->body);
        $this->assertSame('manuela', $reply->sent_by);
        $this->assertSame('sent', $reply->status);
        $this->assertSame('wamid.OUT1', $reply->wa_message_id);
        $this->assertSame('Oi Maria! Qual modelo você procura?', $conversation->fresh()->last_message_preview);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'alphakora.test/v1/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer hermes-key')
            && $request['messages'][0]['role'] === 'system'
            && end($request->data()['messages'])['content'] === 'Oi, tem campainha?');
    }

    public function test_reentrega_da_meta_nao_duplica_nem_responde_de_novo(): void
    {
        $this->enableManuela();
        Http::fake([
            'alphakora.test/*' => Http::response(['choices' => [['message' => ['content' => 'Oi!']]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.SAME', 'Oi'))->assertOk();
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.SAME', 'Oi'))->assertOk();

        $this->assertSame(1, WhatsAppMessage::query()->where('direction', 'inbound')->count());
        $this->assertSame(1, WhatsAppMessage::query()->where('direction', 'outbound')->count());
        $this->assertSame(1, WhatsAppConversation::query()->first()->unread_count);
    }

    public function test_tag_humano_sinaliza_a_conversa(): void
    {
        $this->enableManuela();
        Http::fake([
            'alphakora.test/*' => Http::response(['choices' => [['message' => ['content' => '[HUMANO] Vou chamar alguém do time pra te ajudar.']]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.H1', 'quero falar do meu pedido atrasado'))->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertTrue($conversation->needs_human);
        $this->assertSame('Vou chamar alguém do time pra te ajudar.', WhatsAppMessage::query()->where('direction', 'outbound')->value('body'));
    }

    public function test_hermes_fora_do_ar_cai_nas_regras_locais(): void
    {
        $this->enableManuela();
        Http::fake([
            'alphakora.test/*' => Http::response('bad gateway', 502),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.F1', 'qual o prazo de entrega?'))->assertOk();

        $reply = WhatsAppMessage::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertStringContainsString('CEP', $reply->body);
        $this->assertSame('regras', $reply->payload['manuela']['source']);
    }

    public function test_atendente_responde_e_assume_a_conversa(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HUM']]])]);
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.A1', 'Oi'))->assertOk();
        $conversation = WhatsAppConversation::query()->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/mensagens", ['body' => 'Oi, aqui é do time!'])
            ->assertOk()
            ->assertJsonPath('message.status', 'sent')
            ->assertJsonPath('message.sentBy', $admin->name)
            ->assertJsonPath('conversation.aiEnabled', false)
            ->assertJsonPath('conversation.unread', 0);

        $this->actingAs($admin)
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/manuela", ['ai_enabled' => true])
            ->assertJsonPath('conversation.aiEnabled', true);
    }

    public function test_sem_token_da_meta_a_mensagem_fica_salva_sem_envio(): void
    {
        config(['services.whatsapp.access_token' => null]);
        Http::fake();
        $conversation = WhatsAppConversation::query()->create(['wa_id' => '5511988887777', 'phone' => '5511988887777']);

        $this->actingAs($this->admin())
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/mensagens", ['body' => 'Teste'])
            ->assertOk()
            ->assertJsonPath('message.status', 'draft_no_token');

        Http::assertNothingSent();
    }

    public function test_atualizacoes_trazem_so_o_que_mudou(): void
    {
        Http::fake();
        $admin = $this->admin();
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.U1', 'primeira'))->assertOk();
        $conversation = WhatsAppConversation::query()->firstOrFail();

        $this->actingAs($admin)->get('/admin/whatsapp/conversas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/WhatsApp/Inbox', false)->has('conversations', 1));

        $this->travel(10)->seconds();
        $since = now()->toISOString();
        $this->travel(5)->seconds();
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.U2', 'segunda'))->assertOk();

        $this->actingAs($admin)
            ->getJson('/admin/whatsapp/conversas/atualizacoes?'.http_build_query(['since' => $since, 'conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.unread', 2)
            ->assertJsonPath('conversations.0.preview', 'segunda')
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'segunda');

        $this->actingAs($admin)->postJson("/admin/whatsapp/conversas/{$conversation->id}/lida")->assertJsonPath('conversation.unread', 0);
    }

    public function test_quem_nao_ve_pedidos_nao_acessa_conversas(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get('/admin/whatsapp/conversas')->assertForbidden();
    }
}

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
            'services.gemini.api_key' => null,
            'services.gemini.chat_model' => 'gemini-test',
            'services.gemini.audio_model' => 'gemini-audio-test',
            'services.gemini.fallback_models' => ['gemini-reserva-test'],
            'services.gemini.retry_delay_ms' => 0,
        ]);

        // Os testes antigos partem da chave desligada nas conversas novas.
        app(WhatsAppSettings::class)->setMany(['auto_reply_enabled' => false]);
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

    public function test_regras_nao_repetem_a_saudacao_e_chamam_uma_pessoa(): void
    {
        config(['services.whatsapp.manuela_url' => null]);
        $this->enableManuela();
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.R1']]])
            ->push(['messages' => [['id' => 'wamid.R2']]])]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.S1', 'Oie, vc libera amostra grátis?'))->assertOk();
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.S2', 'Camera para computador'))->assertOk();

        $replies = WhatsAppMessage::query()->where('direction', 'outbound')->orderBy('id')->pluck('body');
        $this->assertCount(2, $replies);
        $this->assertNotSame($replies[0], $replies[1]);
        $this->assertStringContainsString('pessoa do time', $replies[1]);
        $this->assertTrue(WhatsAppConversation::query()->first()->needs_human);

        // Alguém religou a chave sem responder: o aviso de "vou chamar uma
        // pessoa" não sai de novo.
        WhatsAppConversation::query()->first()->update(['needs_human' => false]);
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.S3', 'Ok'))->assertOk();
        $this->assertSame(2, WhatsAppMessage::query()->where('direction', 'outbound')->count());
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

    public function test_avisos_do_admin_trazem_so_mensagens_recebidas_depois_do_ultimo_id(): void
    {
        Http::fake();
        $admin = $this->admin();
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.T1', 'antiga'))->assertOk();

        $first = $this->actingAs($admin)->getJson('/admin/whatsapp/conversas/chegando')
            ->assertOk()
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('unreadConversations', 1);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.T2', 'nova chegando'))->assertOk();

        $this->actingAs($admin)->getJson('/admin/whatsapp/conversas/chegando?after='.$first->json('lastId'))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.preview', 'nova chegando')
            ->assertJsonPath('messages.0.name', 'Maria Souza');
    }

    public function test_ligar_a_chave_faz_a_manuela_responder_quem_esta_esperando(): void
    {
        Http::fake([
            'alphakora.test/*' => Http::response(['choices' => [['message' => ['content' => 'Oi! Sou a Manuela.']]]]),
            'graph.facebook.com/*' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.M1']]])
                ->push(['messages' => [['id' => 'wamid.M2']]]),
        ]);

        // Resposta automática desligada: conversa nova nasce com a chave desligada.
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.K1', 'Tenho uma dúvida'))->assertOk();
        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertFalse($conversation->ai_enabled);
        $this->assertSame(0, WhatsAppMessage::query()->where('direction', 'outbound')->count());

        $this->actingAs($this->admin())
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/manuela", ['ai_enabled' => true])
            ->assertJsonPath('conversation.aiEnabled', true);

        $reply = WhatsAppMessage::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame('manuela', $reply->sent_by);
        $this->assertSame('Oi! Sou a Manuela.', $reply->body);

        // Com a chave ligada, a próxima mensagem já é respondida direto.
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.K2', 'E o prazo?'))->assertOk();
        $this->assertSame(2, WhatsAppMessage::query()->where('direction', 'outbound')->count());
    }

    public function test_apagar_conversa_tira_ela_e_as_mensagens(): void
    {
        Http::fake();
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.D1', 'Oi'))->assertOk();
        $conversation = WhatsAppConversation::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->deleteJson("/admin/whatsapp/conversas/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('deleted', $conversation->id);

        $this->assertSame(0, WhatsAppConversation::query()->count());
        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    private function useGemini(): void
    {
        config(['services.whatsapp.manuela_url' => null, 'services.gemini.api_key' => 'gemini-key']);
        $this->enableManuela();
    }

    private function geminiText(string $text): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => 'pensando...', 'thought' => true], ['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 40, 'thoughtsTokenCount' => 60, 'totalTokenCount' => 1300],
        ];
    }

    public function test_manuela_responde_pelo_gemini_com_a_persona_e_a_busca_de_produtos(): void
    {
        $this->useGemini();
        $product = \App\Modules\Catalog\Models\Product::factory()->create(['name' => 'Campainha Sem Fio Câmera', 'slug' => 'campainha-sem-fio', 'price' => 199.9, 'stock' => 5, 'is_active' => true]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiText('Oi! Eu sou a Manuela, da KazaKora. Temos sim a Campainha Sem Fio Câmera.')),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.G1']]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.GIN1', 'Vocês têm campainha?'))->assertOk();

        $reply = WhatsAppMessage::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame('Oi! Eu sou a Manuela, da KazaKora. Temos sim a Campainha Sem Fio Câmera.', $reply->body);

        $usage = \App\Modules\WhatsApp\Models\GeminiUsageLog::query()->sole();
        $this->assertSame(['gemini-test', 'resposta', 1200, 100, 1300], [$usage->model, $usage->purpose, $usage->prompt_tokens, $usage->output_tokens, $usage->total_tokens]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/admin/whatsapp')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/WhatsApp/Index', false)
                ->where('aiUsage.today.calls', 1)
                ->where('aiUsage.today.total', 1300)
                ->where('aiUsage.byModel.0.model', 'gemini-test'));
        $this->assertSame('gemini', $reply->payload['manuela']['source']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'models/gemini-test:generateContent')
            && $request->hasHeader('x-goog-api-key', 'gemini-key')
            && str_contains($request['systemInstruction']['parts'][0]['text'], 'Você é a Manuela')
            && str_contains($request['systemInstruction']['parts'][0]['text'], 'chame buscar_produto')
            && $request['tools'][0]['functionDeclarations'][0]['name'] === 'buscar_produto'
            && $request['contents'][0]['role'] === 'user'
            && $request['contents'][0]['parts'][0]['text'] === 'Vocês têm campainha?');
    }

    public function test_audio_do_cliente_e_transcrito_e_respondido(): void
    {
        $this->useGemini();
        Http::fake([
            'graph.facebook.com/*/media-audio-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/audio-1', 'mime_type' => 'audio/ogg; codecs=opus']),
            'lookaside.fbsbx.com/*' => Http::response('OGG-BYTES'),
            'generativelanguage.googleapis.com/*gemini-audio-test*' => Http::response($this->geminiText('Qual o prazo pra Campinas?')),
            'generativelanguage.googleapis.com/*gemini-test*' => Http::response($this->geminiText('Me passa seu CEP que eu confiro!')),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.A1']]]),
        ]);

        $payload = $this->inbound('wamid.AUD1', '');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'audio';
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['audio'] = ['id' => 'media-audio-1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true];

        $this->postJson('/api/whatsapp/webhook', $payload)->assertOk();

        $this->assertSame(['transcricao', 'resposta'], \App\Modules\WhatsApp\Models\GeminiUsageLog::query()->orderBy('id')->pluck('purpose')->all());

        $audio = WhatsAppMessage::query()->where('direction', 'inbound')->firstOrFail();
        $this->assertSame('Qual o prazo pra Campinas?', $audio->payload['transcription']);
        $this->assertSame('Me passa seu CEP que eu confiro!', WhatsAppMessage::query()->where('direction', 'outbound')->value('body'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-audio-test')
            && $request['contents'][0]['parts'][0]['inline_data']['mime_type'] === 'audio/ogg'
            && $request['contents'][0]['parts'][0]['inline_data']['data'] === base64_encode('OGG-BYTES'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'models/gemini-test:')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Qual o prazo pra Campinas?'));
    }

    public function test_gemini_sobrecarregado_tenta_de_novo_e_usa_o_modelo_reserva(): void
    {
        $this->useGemini();
        $busy = ['error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.']];
        Http::fake([
            'generativelanguage.googleapis.com/*gemini-test*' => Http::sequence()->push($busy, 503)->push($busy, 503),
            'generativelanguage.googleapis.com/*gemini-reserva-test*' => Http::response($this->geminiText('Oi! Eu sou a Manuela, da KazaKora.')),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.B1']]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.BUSY1', 'Oi'))->assertOk();

        $this->assertSame('Oi! Eu sou a Manuela, da KazaKora.', WhatsAppMessage::query()->where('direction', 'outbound')->value('body'));
        Http::assertSentCount(4);
    }

    public function test_google_fora_do_ar_tenta_de_novo_em_vez_de_mandar_mensagem_robotica(): void
    {
        $this->useGemini();
        \Illuminate\Support\Facades\Queue::fake();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 503]], 503),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X1']]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.DOWN1', 'Vi a caixa de ferramentas de 168 peças'))->assertOk();

        $this->assertSame(0, WhatsAppMessage::query()->where('direction', 'outbound')->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\Illuminate\Queue\CallQueuedClosure::class);
    }

    public function test_precisa_de_humano_nao_cala_a_manuela(): void
    {
        $this->useGemini();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiText('Entendi, já avisei o time e sigo aqui com você.')),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.N1']]]),
        ]);
        WhatsAppConversation::query()->create(['wa_id' => '5511999990000', 'phone' => '5511999990000', 'ai_enabled' => true, 'needs_human' => true, 'status' => 'needs_human']);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.NH1', 'Ainda estou esperando'))->assertOk();

        $this->assertSame('Entendi, já avisei o time e sigo aqui com você.', WhatsAppMessage::query()->where('direction', 'outbound')->value('body'));
    }

    public function test_humano_assumir_tira_o_alerta_de_precisa_de_humano(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.H1']]])]);
        $admin = $this->admin();
        $alerta = ['ai_enabled' => true, 'needs_human' => true, 'status' => 'needs_human', 'last_message_preview' => 'Oi'];
        $chave = WhatsAppConversation::query()->create(['wa_id' => '5511911110000', 'phone' => '5511911110000'] + $alerta);
        $resposta = WhatsAppConversation::query()->create(['wa_id' => '5511922220000', 'phone' => '5511922220000'] + $alerta);

        $this->actingAs($admin)
            ->postJson("/admin/whatsapp/conversas/{$chave->id}/manuela", ['ai_enabled' => false])
            ->assertJsonPath('conversation.aiEnabled', false)
            ->assertJsonPath('conversation.needsHuman', false)
            ->assertJsonPath('conversation.status', 'open');

        $this->actingAs($admin)
            ->postJson("/admin/whatsapp/conversas/{$resposta->id}/mensagens", ['body' => 'Oi, aqui é do time'])
            ->assertJsonPath('conversation.needsHuman', false)
            ->assertJsonPath('conversation.status', 'open');
    }

    public function test_quem_nao_ve_pedidos_nao_acessa_conversas(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get('/admin/whatsapp/conversas')->assertForbidden();
    }
}

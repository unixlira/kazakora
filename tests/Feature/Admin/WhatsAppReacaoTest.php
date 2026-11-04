<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reação com emoji no WhatsApp (pedido do Lira 04/11): a reação do cliente
 * gruda no balão da mensagem reagida (não vira balão nem anexo "reaction"),
 * a lista mostra "Reagiu com 👍", a Manuela não responde reação, e o
 * atendente reage pela tela com as reações rápidas.
 */
class WhatsAppReacaoTest extends TestCase
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
        ]);

        app(WhatsAppSettings::class)->setMany(['enabled' => true, 'auto_reply_enabled' => false]);
    }

    private function webhook(array $message, string $from = '5511999990000'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Maria Souza']]],
                        'messages' => [$message + ['from' => $from, 'timestamp' => (string) now()->timestamp]],
                    ],
                ]],
            ]],
        ];
    }

    private function texto(string $id, string $body): array
    {
        return $this->webhook(['id' => $id, 'type' => 'text', 'text' => ['body' => $body]]);
    }

    private function reacao(string $id, string $alvo, string $emoji): array
    {
        return $this->webhook(['id' => $id, 'type' => 'reaction', 'reaction' => ['message_id' => $alvo, 'emoji' => $emoji]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_reacao_recebida_vai_para_o_balao_reagido_e_a_lista_mostra_reagiu_com(): void
    {
        Bus::fake([ReplyWithManuela::class]);

        $this->postJson('/api/whatsapp/webhook', $this->texto('wamid.IN1', 'Chegou certinho'))->assertOk();
        $this->postJson('/api/whatsapp/webhook', $this->reacao('wamid.R1', 'wamid.IN1', '👍'))->assertOk();

        $conversation = WhatsAppConversation::query()->sole();
        $alvo = WhatsAppMessage::query()->where('wa_message_id', 'wamid.IN1')->sole();

        $this->assertSame('Reagiu com 👍', $conversation->last_message_preview);
        $this->assertSame(1, WhatsAppConversation::query()->count());

        $mensagens = $this->actingAs($this->admin())
            ->getJson("/admin/whatsapp/conversas/{$conversation->id}/mensagens")
            ->assertOk()
            ->json('messages');

        $reacao = collect($mensagens)->firstWhere('type', 'reaction');
        $this->assertSame(['emoji' => '👍', 'targetId' => $alvo->id], $reacao['reaction']);
        $this->assertNull(collect($mensagens)->firstWhere('id', $alvo->id)['reaction']);
    }

    public function test_reacao_tirada_pelo_cliente_volta_vazia_sem_mudar_a_previa(): void
    {
        Bus::fake([ReplyWithManuela::class]);

        $this->postJson('/api/whatsapp/webhook', $this->texto('wamid.IN1', 'Oi'))->assertOk();
        $this->postJson('/api/whatsapp/webhook', $this->reacao('wamid.R1', 'wamid.IN1', '❤️'))->assertOk();
        $this->postJson('/api/whatsapp/webhook', $this->reacao('wamid.R2', 'wamid.IN1', ''))->assertOk();

        $conversation = WhatsAppConversation::query()->sole();
        $this->assertSame('Reagiu com ❤️', $conversation->last_message_preview);
        // Só o texto e a reação contam como não lidas; tirar a reação não.
        $this->assertSame(2, $conversation->unread_count);

        $tirada = WhatsAppMessage::query()->where('wa_message_id', 'wamid.R2')->sole();
        $this->assertSame('', $tirada->reacao()['emoji']);
        $this->assertSame('Removeu a reação', $tirada->preview());

        $mensagens = $this->actingAs($this->admin())
            ->getJson("/admin/whatsapp/conversas/{$conversation->id}/mensagens")
            ->json('messages');
        $this->assertSame('', collect($mensagens)->firstWhere('id', $tirada->id)['reaction']['emoji']);
    }

    public function test_manuela_nao_responde_reacao(): void
    {
        Bus::fake([ReplyWithManuela::class]);
        app(WhatsAppSettings::class)->setMany(['auto_reply_enabled' => true]);

        $this->postJson('/api/whatsapp/webhook', $this->texto('wamid.IN1', 'Oi'))->assertOk();
        Bus::assertDispatchedTimes(ReplyWithManuela::class, 1);

        $this->postJson('/api/whatsapp/webhook', $this->reacao('wamid.R1', 'wamid.IN1', '👍'))->assertOk();
        Bus::assertDispatchedTimes(ReplyWithManuela::class, 1);
    }

    private function conversaComMensagem(): array
    {
        $conversation = WhatsAppConversation::paraNumero('5511999990000', WhatsAppNumeros::KAZAKORA, ['ai_enabled' => false]);
        $conversation->update(['last_customer_message_at' => now(), 'last_message_preview' => 'Oi']);
        $alvo = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'wa_message_id' => 'wamid.IN1',
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Chegou certinho',
            'status' => 'received',
            'received_at' => now(),
        ]);

        return [$conversation->fresh(), $alvo];
    }

    public function test_atendente_reage_pela_cloud_api_e_a_reacao_aparece_no_balao(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUTR1']]])]);
        [$conversation, $alvo] = $this->conversaComMensagem();

        $resposta = $this->actingAs($this->admin())
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/reagir", ['message_id' => $alvo->id, 'emoji' => '❤️'])
            ->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/123/messages')
            && $request['type'] === 'reaction'
            && $request['to'] === '5511999990000'
            && $request['reaction'] === ['message_id' => 'wamid.IN1', 'emoji' => '❤️']);

        $resposta->assertJsonPath('message.type', 'reaction')
            ->assertJsonPath('message.direction', 'outbound')
            ->assertJsonPath('message.status', 'sent')
            ->assertJsonPath('message.reaction.emoji', '❤️')
            ->assertJsonPath('message.reaction.targetId', $alvo->id)
            ->assertJsonPath('conversation.preview', 'Você reagiu com ❤️');

        $gravada = WhatsAppMessage::query()->where('type', 'reaction')->sole();
        $this->assertSame('wamid.OUTR1', $gravada->wa_message_id);
        $this->assertSame('outbound', $gravada->direction);
        // Reagir não desliga a Manuela nem cria conversa nova.
        $this->assertSame(1, WhatsAppConversation::query()->count());
    }

    public function test_atendente_tira_a_reacao_com_emoji_vazio(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUTR2']]])]);
        [$conversation, $alvo] = $this->conversaComMensagem();

        $this->actingAs($this->admin())
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/reagir", ['message_id' => $alvo->id, 'emoji' => ''])
            ->assertOk()
            ->assertJsonPath('message.reaction.emoji', '');

        Http::assertSent(fn ($request) => $request['type'] === 'reaction'
            && $request['reaction'] === ['message_id' => 'wamid.IN1', 'emoji' => '']);
        // Tirar não muda a prévia da lista.
        $this->assertSame('Oi', $conversation->fresh()->last_message_preview);
    }

    public function test_atendente_reage_com_emoji_do_seletor_mais(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUTR3']]])]);
        [$conversation, $alvo] = $this->conversaComMensagem();

        $this->actingAs($this->admin())
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/reagir", ['message_id' => $alvo->id, 'emoji' => '🔥'])
            ->assertOk()
            ->assertJsonPath('message.reaction.emoji', '🔥');

        Http::assertSent(fn ($request) => $request['type'] === 'reaction'
            && $request['reaction'] === ['message_id' => 'wamid.IN1', 'emoji' => '🔥']);
    }

    public function test_reagir_recusa_emoji_fora_da_lista_e_mensagem_de_outra_conversa(): void
    {
        Http::fake();
        [$conversation, $alvo] = $this->conversaComMensagem();
        $outra = WhatsAppConversation::paraNumero('5511888880000', WhatsAppNumeros::KAZAKORA);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/reagir", ['message_id' => $alvo->id, 'emoji' => '💩'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson("/admin/whatsapp/conversas/{$outra->id}/reagir", ['message_id' => $alvo->id, 'emoji' => '👍'])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::query()->where('type', 'reaction')->count());
    }
}

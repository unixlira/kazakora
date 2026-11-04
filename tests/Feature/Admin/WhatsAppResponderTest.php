<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Responder com citação na tela de Conversas (pedido do Lira 04/11): a
 * mensagem sai com o "context" da Cloud API (wamid citado) e o balão
 * enviado traz a citação.
 */
class WhatsAppResponderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.gemini.api_key' => null,
        ]);

        app(WhatsAppSettings::class)->setMany(['enabled' => true, 'auto_reply_enabled' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
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
            'body' => 'Qual o prazo de entrega?',
            'status' => 'received',
            'received_at' => now(),
        ]);

        return [$conversation->fresh(), $alvo];
    }

    public function test_responder_manda_o_context_e_o_balao_traz_a_citacao(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
        [$conversation, $alvo] = $this->conversaComMensagem();

        $this->actingAs($this->admin())
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/mensagens", ['body' => 'Chega em 3 dias úteis', 'reply_to' => $alvo->id])
            ->assertOk()
            ->assertJsonPath('message.body', 'Chega em 3 dias úteis')
            ->assertJsonPath('message.replyTo.id', $alvo->id)
            ->assertJsonPath('message.replyTo.direction', 'inbound')
            ->assertJsonPath('message.replyTo.body', 'Qual o prazo de entrega?');

        Http::assertSent(fn ($request) => $request['type'] === 'text'
            && $request['text']['body'] === 'Chega em 3 dias úteis'
            && $request['context'] === ['message_id' => 'wamid.IN1']);

        // A citação continua no histórico ao reabrir a conversa.
        $mensagens = $this->actingAs($this->admin())
            ->getJson("/admin/whatsapp/conversas/{$conversation->id}/mensagens")
            ->json('messages');
        $this->assertSame($alvo->id, collect($mensagens)->firstWhere('body', 'Chega em 3 dias úteis')['replyTo']['id']);
        $this->assertNull(collect($mensagens)->firstWhere('id', $alvo->id)['replyTo']);
    }

    public function test_mensagem_sem_citacao_nao_manda_context(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT2']]])]);
        [$conversation] = $this->conversaComMensagem();

        $this->actingAs($this->admin())
            ->postJson("/admin/whatsapp/conversas/{$conversation->id}/mensagens", ['body' => 'Oi!'])
            ->assertOk()
            ->assertJsonPath('message.replyTo', null);

        Http::assertSent(fn ($request) => $request['type'] === 'text' && ! isset($request['context']));
    }

    public function test_responder_recusa_mensagem_de_outra_conversa(): void
    {
        Http::fake();
        [, $alvo] = $this->conversaComMensagem();
        $outra = WhatsAppConversation::paraNumero('5511888880000', WhatsAppNumeros::KAZAKORA, ['ai_enabled' => false]);
        $outra->update(['last_customer_message_at' => now()]);

        $this->actingAs($this->admin())
            ->postJson("/admin/whatsapp/conversas/{$outra->id}/mensagens", ['body' => 'Oi', 'reply_to' => $alvo->id])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::query()->where('direction', 'outbound')->count());
    }
}

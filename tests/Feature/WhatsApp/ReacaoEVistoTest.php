<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-11-17: reação aparecia como "Mensagem do tipo reaction"
 * e o tique não ficava azul quando o cliente já tinha visto.
 */
class ReacaoEVistoTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppConversation $conversa;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.manuela_url' => null,
            'services.gemini.api_key' => null,
        ]);
        app(WhatsAppSettings::class)->setMany(['auto_reply_enabled' => true]);
        Http::fake();

        $this->conversa = WhatsAppConversation::paraNumero('5511999990000', 'kazakora', ['phone' => '5511999990000', 'ai_enabled' => false]);
    }

    private function saida(string $wamid, string $status): WhatsAppMessage
    {
        return WhatsAppMessage::query()->create([
            'conversation_id' => $this->conversa->id,
            'wa_message_id' => $wamid,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Olá',
            'status' => $status,
            'sent_at' => now(),
        ]);
    }

    private function webhook(array $value): void
    {
        $value['metadata'] = ['phone_number_id' => '123'];
        $this->postJson('/api/webhooks/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => $value]]]]])->assertOk();
    }

    private function reacao(string $id, string $alvo, string $emoji): void
    {
        $this->webhook([
            'contacts' => [['wa_id' => '5511999990000', 'profile' => ['name' => 'Cliente']]],
            'messages' => [['from' => '5511999990000', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'reaction', 'reaction' => ['message_id' => $alvo, 'emoji' => $emoji]]],
        ]);
    }

    private function mensagensDaTela(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return $this->actingAs($admin)->getJson("/admin/whatsapp/conversas/{$this->conversa->id}/mensagens")->assertOk()->json('messages');
    }

    public function test_reacao_vira_selo_na_mensagem_e_nao_bolha_solta(): void
    {
        $this->saida('wamid.A', 'delivered');
        $this->reacao('wamid.R1', 'wamid.A', '🙏');

        $mensagens = $this->mensagensDaTela();
        $this->assertCount(1, $mensagens);
        $this->assertSame(['🙏'], $mensagens[0]['reactions']);
        $this->assertSame('Reagiu com 🙏', $this->conversa->fresh()->last_message_preview);

        // Trocar a reação vale a última; emoji vazio remove.
        $this->reacao('wamid.R2', 'wamid.A', '❤️');
        $this->assertSame(['❤️'], $this->mensagensDaTela()[0]['reactions']);
        $this->reacao('wamid.R3', 'wamid.A', '');
        $this->assertSame([], $this->mensagensDaTela()[0]['reactions']);
    }

    public function test_lida_na_ultima_pinta_as_anteriores_e_delivered_atrasado_nao_volta(): void
    {
        $a = $this->saida('wamid.A', 'delivered');
        $b = $this->saida('wamid.B', 'sent');
        $c = $this->saida('wamid.C', 'delivered');

        $this->webhook(['statuses' => [['id' => 'wamid.C', 'status' => 'read', 'recipient_id' => '5511999990000']]]);
        $this->webhook(['statuses' => [['id' => 'wamid.C', 'status' => 'delivered', 'recipient_id' => '5511999990000']]]);

        $this->assertSame(['read', 'read', 'read'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
    }

    public function test_cliente_respondeu_entao_viu(): void
    {
        $a = $this->saida('wamid.A', 'delivered');

        $this->webhook([
            'contacts' => [['wa_id' => '5511999990000', 'profile' => ['name' => 'Cliente']]],
            'messages' => [['from' => '5511999990000', 'id' => 'wamid.IN', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Obrigado']]],
        ]);

        $this->assertSame('read', $a->fresh()->status);
    }
}

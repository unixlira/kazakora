<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Acompanhamento da Manuela após 30 min de silêncio do cliente (2026-10-20). */
class ManuelaAcompanhamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
        ]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        $this->travelTo(now()->setTime(14, 0));
    }

    /** Conversa em que a Manuela respondeu há $minutos. */
    private function conversa(int $minutos = 40, string $clienteDisse = 'Quanto custa a webcam?', array $conversa = [], array $ultima = []): WhatsAppConversation
    {
        $c = WhatsAppConversation::create($conversa + [
            'wa_id' => '5511988887777', 'phone' => '5511988887777', 'profile_name' => 'Maria da Silva',
            'status' => 'open', 'ai_enabled' => true, 'needs_human' => false,
            'last_customer_message_at' => now()->subMinutes($minutos + 1),
        ])->fresh();
        WhatsAppMessage::create(['conversation_id' => $c->id, 'direction' => 'inbound', 'type' => 'text', 'body' => $clienteDisse, 'status' => 'received', 'received_at' => now()->subMinutes($minutos + 1)]);
        $m = WhatsAppMessage::create($ultima + ['conversation_id' => $c->id, 'direction' => 'outbound', 'type' => 'text', 'body' => 'Custa R$ 99,90.', 'status' => 'sent', 'sent_by' => 'manuela', 'sent_at' => now()->subMinutes($minutos)]);
        $c->registerMessage($m);

        return $c->fresh();
    }

    private function rodar(): void
    {
        $this->artisan('manuela:acompanhamento')->assertSuccessful();
    }

    private function acompanhamentos(WhatsAppConversation $c): int
    {
        return $c->messages()->where('direction', 'outbound')->get()->filter(fn ($m) => $m->payload['followup'] ?? false)->count();
    }

    public function test_cliente_calado_ha_mais_de_30_minutos_recebe_acompanhamento_uma_vez(): void
    {
        $c = $this->conversa(40);

        $this->rodar();
        $this->rodar();

        $this->assertSame(1, $this->acompanhamentos($c));
        $mensagem = $c->messages()->where('direction', 'outbound')->latest('id')->first();
        $this->assertStringContainsString('Oi Maria,', $mensagem->body);
        $this->assertStringContainsString('alguma dúvida', $mensagem->body);
        $this->assertSame('manuela', $mensagem->sent_by);
        Http::assertSentCount(1);
    }

    public function test_menos_de_30_minutos_nao_envia(): void
    {
        $c = $this->conversa(20);
        $this->rodar();
        $this->assertSame(0, $this->acompanhamentos($c));
    }

    public function test_nao_envia_quando_a_ultima_foi_de_uma_pessoa_ou_chamou_humano(): void
    {
        $pessoa = $this->conversa(40, ultima: ['sent_by' => 'user']);
        $handoff = $this->conversa(40, conversa: ['wa_id' => '5511977776666', 'phone' => '5511977776666'], ultima: ['body' => ManuelaAutoReplyService::HANDOFF_REPLY]);
        $precisaHumano = $this->conversa(40, conversa: ['wa_id' => '5511966665555', 'phone' => '5511966665555', 'needs_human' => true]);

        $this->rodar();

        foreach ([$pessoa, $handoff, $precisaHumano] as $c) {
            $this->assertSame(0, $this->acompanhamentos($c));
        }
    }

    public function test_nao_envia_com_ia_desligada_fora_da_janela_de_24h_ou_apos_despedida(): void
    {
        $semIa = $this->conversa(40, conversa: ['ai_enabled' => false]);
        $antiga = $this->conversa(60 * 25, conversa: ['wa_id' => '5511955554444', 'phone' => '5511955554444']);
        $despedida = $this->conversa(40, 'Obrigada!', ['wa_id' => '5511944443333', 'phone' => '5511944443333']);

        $this->rodar();

        foreach ([$semIa, $antiga, $despedida] as $c) {
            $this->assertSame(0, $this->acompanhamentos($c));
        }
    }

    public function test_nao_envia_de_madrugada(): void
    {
        $this->travelTo(now()->setTime(23, 0));
        $c = $this->conversa(40);
        $this->rodar();
        $this->assertSame(0, $this->acompanhamentos($c));
    }

    public function test_volta_a_valer_depois_que_o_cliente_responde_e_a_manuela_responde(): void
    {
        $c = $this->conversa(40);
        $this->rodar();
        $this->assertSame(1, $this->acompanhamentos($c));

        $this->travel(2)->hours();
        $c = $c->fresh();
        $c->update(['last_customer_message_at' => now()->subMinutes(41)]);
        WhatsAppMessage::create(['conversation_id' => $c->id, 'direction' => 'inbound', 'type' => 'text', 'body' => 'E o frete?', 'status' => 'received', 'received_at' => now()->subMinutes(41)]);
        $m = WhatsAppMessage::create(['conversation_id' => $c->id, 'direction' => 'outbound', 'type' => 'text', 'body' => 'Me manda seu CEP.', 'status' => 'sent', 'sent_by' => 'manuela', 'sent_at' => now()->subMinutes(40)]);
        $c->registerMessage($m);

        $this->rodar();

        $this->assertSame(2, $this->acompanhamentos($c));
    }
}

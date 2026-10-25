<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Notifications\ManuelaAlertaAtendimentoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-10-25: a Manuela lê a descrição do produto antes de
 * responder e, se a resposta for negativa ("não sei", "não tem"), não manda
 * nada ao cliente: alerta o Atendimento para assumir.
 */
class ManuelaRespostaNegativaTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_manda_ler_a_descricao_e_alertar_em_vez_de_responder_negativa(): void
    {
        foreach ([null, WhatsAppConversation::make(['loja' => 'ferrakora'])] as $conversa) {
            $prompt = app(ManuelaAutoReplyService::class)->systemPrompt($conversa);

            $this->assertStringContainsString(ManuelaAgentClient::ALERT_TAG, $prompt);
            $this->assertStringContainsString('NÃO responda isso ao cliente', $prompt);
        }

        $this->assertStringContainsString('leia a descrição inteira da ficha', app(ManuelaAutoReplyService::class)->systemPrompt());
    }

    public function test_respostas_negativas_sao_reconhecidas_e_as_normais_nao(): void
    {
        foreach (['Não sei te dizer.', 'Infelizmente não temos essa cor.', 'Não tem esse modelo.', 'Não encontrei esse produto.', 'Não tenho essa informação.'] as $texto) {
            $this->assertTrue(ManuelaAgentClient::respostaNegativa($texto), $texto);
        }

        foreach (['Tem sim! A voltagem é bivolt.', 'Não tem problema, posso te ajudar com isso.', 'Pode usar em 110V e 220V.'] as $texto) {
            $this->assertFalse(ManuelaAgentClient::respostaNegativa($texto), $texto);
        }
    }

    public function test_negativa_nao_vai_ao_cliente_e_alerta_os_admins(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $conversa = WhatsAppConversation::create(['wa_id' => '5511999990000', 'phone' => '5511999990000', 'ai_enabled' => true]);
        $inbound = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Essa serve na minha moto?', 'status' => 'received']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $agent = Mockery::mock(ManuelaAgentClient::class);
        $agent->shouldReceive('isConfigured')->andReturn(true);
        $manuela = Mockery::mock(ManuelaAutoReplyService::class);
        $manuela->shouldReceive('buildReply')->andReturn([
            'reply' => '', 'needs_human' => true, 'alerta_atendimento' => true, 'rascunho' => 'Não sei se serve.', 'source' => 'gemini',
        ]);
        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldNotReceive('sendText');

        (new ReplyWithManuela($conversa->id, $inbound->id))->handle($manuela, $outbox, $agent, $gemini, Mockery::mock(WhatsAppMediaDownloader::class));

        $this->assertTrue($conversa->fresh()->needs_human);
        \Illuminate\Support\Facades\Notification::assertSentTo($admin, ManuelaAlertaAtendimentoNotification::class);
    }
}

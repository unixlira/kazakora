<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-10-23: a Manuela entende o áudio do cliente (transcrição)
 * mas responde SEMPRE por texto, para qualquer número.
 */
class ManuelaSoTextoTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_manda_responder_so_por_texto_e_nunca_enviar_audio(): void
    {
        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt();

        $this->assertStringContainsString('responde sempre e somente por TEXTO', $prompt);
        $this->assertStringContainsString('Nunca envie áudio', $prompt);
    }

    public function test_audio_do_cliente_e_respondido_por_texto_inclusive_no_numero_do_lira(): void
    {
        config(['services.whatsapp.audio_reply_numbers' => ['5511990253050']]);

        $conversa = WhatsAppConversation::create(['wa_id' => '5511990253050', 'phone' => '5511990253050', 'ai_enabled' => true]);
        $audio = $conversa->messages()->create([
            'direction' => 'inbound', 'type' => 'audio', 'body' => 'quero a caixa de ferramentas', 'status' => 'received',
            'payload' => ['transcription' => 'quero a caixa de ferramentas'],
        ]);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);

        $manuela = Mockery::mock(ManuelaAutoReplyService::class);
        $manuela->shouldReceive('buildReply')
            ->withArgs(fn ($c, $texto) => $texto === 'quero a caixa de ferramentas')
            ->andReturn(['reply' => 'Oi! Qual caixa você procura?', 'source' => 'ia']);
        $agent = Mockery::mock(ManuelaAgentClient::class);
        $agent->shouldReceive('isConfigured')->andReturn(true);

        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendText')->once()->andReturn(new WhatsAppMessage);
        $outbox->shouldNotReceive('sendVoice');

        (new ReplyWithManuela($conversa->id, $audio->id))->handle($manuela, $outbox, $agent, $gemini, Mockery::mock(WhatsAppMediaDownloader::class));

        $this->assertFalse(method_exists(WhatsAppOutbox::class, 'sendVoice'));
        $this->assertFalse(method_exists(GeminiClient::class, 'speak'));
    }
}

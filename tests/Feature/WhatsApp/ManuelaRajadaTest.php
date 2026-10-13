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
 * Achado real 2026-10-13: 16 mensagens seguidas do Lira geraram 14 respostas.
 */
class ManuelaRajadaTest extends TestCase
{
    use RefreshDatabase;

    private function jobs(WhatsAppConversation $conversa, array $mensagens, WhatsAppOutbox $outbox, ?GeminiClient $gemini = null, ?WhatsAppMediaDownloader $media = null): void
    {
        $g = $gemini ?? Mockery::mock(GeminiClient::class)->shouldReceive('isConfigured')->andReturn(true)->getMock();
        $manuela = Mockery::mock(ManuelaAutoReplyService::class);
        $manuela->shouldReceive('buildReply')->andReturn(['reply' => 'Oi!', 'source' => 'ia']);
        $agent = Mockery::mock(ManuelaAgentClient::class);
        $agent->shouldReceive('isConfigured')->andReturn(true);

        foreach ($mensagens as $mensagem) {
            (new ReplyWithManuela($conversa->id, $mensagem->id))->handle($manuela, $outbox, $agent, $g, $media ?? Mockery::mock(WhatsAppMediaDownloader::class));
        }
    }

    public function test_rajada_de_mensagens_gera_uma_resposta_so(): void
    {
        $conversa = WhatsAppConversation::create(['wa_id' => '5511999990000', 'phone' => '5511999990000', 'ai_enabled' => true]);
        $mensagens = collect(['140', '3.5', '10'])->map(fn ($n) => $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => $n, 'status' => 'received']));

        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendText')->once()->andReturn(new WhatsAppMessage);

        // Todos os jobs da rajada rodam; só o da última mensagem responde.
        $this->jobs($conversa, $mensagens->all(), $outbox);
    }

    public function test_video_vira_descricao_na_conversa(): void
    {
        $conversa = WhatsAppConversation::create(['wa_id' => '5511999990000', 'phone' => '5511999990000', 'ai_enabled' => true]);
        $video = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'video', 'body' => 'quanto custa?', 'status' => 'received', 'payload' => ['video' => ['id' => 'v1']]]);

        $g = Mockery::mock(GeminiClient::class);
        $g->shouldReceive('isConfigured')->andReturn(true);
        $g->shouldReceive('describe')->once()->andReturn('Furadeira de impacto azul em uso.');
        $media = Mockery::mock(WhatsAppMediaDownloader::class);
        $media->shouldReceive('download')->andReturn(['body' => 'mp4', 'mime_type' => 'video/mp4']);

        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendText')->once()->andReturn(new WhatsAppMessage);

        $this->jobs($conversa, [$video], $outbox, $g, $media);

        $this->assertStringContainsString('Furadeira de impacto', $video->fresh()->payload['transcription']);
        $this->assertStringContainsString('quanto custa?', $video->fresh()->payload['transcription']);
    }
}

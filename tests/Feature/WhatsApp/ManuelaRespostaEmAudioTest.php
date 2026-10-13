<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\ManuelaVoz;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-10-13: a Manuela responde por áudio SÓ para o número
 * dele. Cliente comum que manda áudio continua recebendo texto.
 */
class ManuelaRespostaEmAudioTest extends TestCase
{
    use RefreshDatabase;

    private function rodar(string $numero, bool $vozFunciona = true): array
    {
        config(['services.whatsapp.audio_reply_numbers' => ['5511990253050']]);

        $conversa = WhatsAppConversation::create(['wa_id' => $numero, 'phone' => $numero, 'ai_enabled' => true]);
        $audio = $conversa->messages()->create([
            'direction' => 'inbound', 'type' => 'audio', 'body' => 'teste de áudio', 'status' => 'received',
            'payload' => ['transcription' => 'teste de áudio'],
        ]);

        $g = Mockery::mock(GeminiClient::class);
        $g->shouldReceive('isConfigured')->andReturn(true);

        $voz = Mockery::mock(ManuelaVoz::class);
        if ($vozFunciona) {
            $arquivo = tempnam(sys_get_temp_dir(), 'teste-voz-');
            $voz->shouldReceive('gerar')->andReturn($arquivo);
        } else {
            $voz->shouldReceive('gerar')->andThrow(new \RuntimeException('tts fora'));
        }
        $this->app->instance(ManuelaVoz::class, $voz);

        $manuela = Mockery::mock(ManuelaAutoReplyService::class);
        $manuela->shouldReceive('buildReply')->andReturn(['reply' => 'Oi! Recebi seu áudio.', 'source' => 'ia']);
        $agent = Mockery::mock(ManuelaAgentClient::class);
        $agent->shouldReceive('isConfigured')->andReturn(true);

        $chamadas = ['voz' => 0, 'texto' => 0];
        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendVoice')->andReturnUsing(function () use (&$chamadas) {
            $chamadas['voz']++;

            return new WhatsAppMessage;
        });
        $outbox->shouldReceive('sendText')->andReturnUsing(function () use (&$chamadas) {
            $chamadas['texto']++;

            return new WhatsAppMessage;
        });

        (new ReplyWithManuela($conversa->id, $audio->id))->handle($manuela, $outbox, $agent, $g, Mockery::mock(WhatsAppMediaDownloader::class));

        return $chamadas;
    }

    public function test_audio_do_numero_liberado_recebe_audio(): void
    {
        $this->assertSame(['voz' => 1, 'texto' => 0], $this->rodar('5511990253050'));
    }

    public function test_audio_de_cliente_comum_recebe_texto(): void
    {
        $this->assertSame(['voz' => 0, 'texto' => 1], $this->rodar('5511999990000'));
    }

    public function test_se_a_voz_falhar_cai_para_texto(): void
    {
        $this->assertSame(['voz' => 0, 'texto' => 1], $this->rodar('5511990253050', false));
    }
}

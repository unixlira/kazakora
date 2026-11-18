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
use App\Modules\WhatsApp\Support\ManuelaEncerramento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-11-18: reação não é respondida, e mensagem que fecha a
 * conversa ("obrigada", "ok, valeu", 👍) encerra o atendimento sem resposta.
 * Achado real: cliente reagiu 🙏 ao "saiu para entrega" e a Manuela mandou
 * "Como posso te ajudar com o seu pedido?".
 */
class ManuelaEncerramentoTest extends TestCase
{
    use RefreshDatabase;

    public static function encerram(): array
    {
        return array_map(fn ($t) => [$t], [
            'Obrigada', 'obrigado!', 'Muito obrigada pela ajuda', 'ok', 'Ok, valeu', 'tá bom, obrigada', 'Tá bom',
            'Perfeito, obrigado! Boa noite', 'blz', 'Beleza, aguardo', 'Valeu manu', 'Amém, Deus te abençoe', '👍', '🙏🙏',
            'Combinado', 'Show, muito obrigado mesmo', 'Entendi, obrigada', 'obg', 'Ok obrigada, tenha um ótimo dia', 'Tchau',
        ]);
    }

    public static function naoEncerram(): array
    {
        return array_map(fn ($t) => [$t], [
            'Bom dia', 'Oi', 'tudo bem?', 'ok, mas e o frete?', 'Obrigada, quando chega?', 'Ok mas ainda não recebi',
            'quero cancelar', 'obrigada, vou querer mais um', 'qual o prazo', 'Não', 'Boa tarde, meu pedido não chegou',
        ]);
    }

    #[DataProvider('encerram')]
    public function test_reconhece_fim_de_conversa(string $texto): void
    {
        $this->assertTrue(ManuelaEncerramento::encerra($texto), $texto);
    }

    #[DataProvider('naoEncerram')]
    public function test_nao_confunde_pergunta_ou_pedido_com_fim(string $texto): void
    {
        $this->assertFalse(ManuelaEncerramento::encerra($texto), $texto);
    }

    public function test_depois_de_pergunta_nossa_ok_e_resposta_mas_obrigada_encerra(): void
    {
        $this->assertFalse(ManuelaEncerramento::encerra('ok', true));
        $this->assertFalse(ManuelaEncerramento::encerra('Sim', true));
        $this->assertFalse(ManuelaEncerramento::encerra('👍', true));
        $this->assertTrue(ManuelaEncerramento::encerra('Não, obrigada', true));
        $this->assertTrue(ManuelaEncerramento::encerra('Valeu, tchau', true));
    }

    private function rodar(WhatsAppConversation $conversa, WhatsAppMessage $mensagem, WhatsAppOutbox $outbox, ?ManuelaAutoReplyService $manuela = null): void
    {
        $gemini = Mockery::mock(GeminiClient::class)->shouldReceive('isConfigured')->andReturn(true)->getMock();
        $agent = Mockery::mock(ManuelaAgentClient::class)->shouldReceive('isConfigured')->andReturn(true)->getMock();
        if (! $manuela) {
            $manuela = Mockery::mock(ManuelaAutoReplyService::class);
            $manuela->shouldNotReceive('buildReply');
        }

        (new ReplyWithManuela($conversa->id, $mensagem->id))->handle($manuela, $outbox, $agent, $gemini, Mockery::mock(WhatsAppMediaDownloader::class));
    }

    private function conversa(): WhatsAppConversation
    {
        return WhatsAppConversation::create(['wa_id' => '5521999990000', 'phone' => '5521999990000', 'ai_enabled' => true, 'status' => 'open']);
    }

    public function test_obrigada_encerra_sem_responder(): void
    {
        $conversa = $this->conversa();
        $conversa->messages()->create(['direction' => 'outbound', 'type' => 'text', 'body' => 'Seu pedido já saiu para entrega.', 'status' => 'read']);
        $msg = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Obrigada!', 'status' => 'received']);

        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldNotReceive('sendText');
        $this->rodar($conversa, $msg, $outbox);

        $this->assertSame('resolved', $conversa->fresh()->status);
        $this->assertTrue($msg->fresh()->payload['manuela_encerrou']);
    }

    public function test_ok_depois_de_pergunta_nossa_vai_para_a_manuela(): void
    {
        $conversa = $this->conversa();
        $conversa->messages()->create(['direction' => 'outbound', 'type' => 'text', 'body' => 'Posso confirmar o endereço da Rua A, 10?', 'status' => 'read']);
        $msg = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'ok', 'status' => 'received']);

        $manuela = Mockery::mock(ManuelaAutoReplyService::class);
        $manuela->shouldReceive('buildReply')->once()->andReturn(['reply' => 'Confirmado!', 'source' => 'gemini']);
        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendText')->once()->andReturn(new WhatsAppMessage);
        $this->rodar($conversa, $msg, $outbox, $manuela);

        $this->assertSame('open', $conversa->fresh()->status);
    }

    public function test_fim_de_conversa_nao_tira_pendencia_do_time(): void
    {
        $conversa = $this->conversa();
        $conversa->update(['needs_human' => true, 'status' => 'needs_human']);
        $msg = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'ok obrigada', 'status' => 'received']);

        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldNotReceive('sendText');
        $this->rodar($conversa, $msg, $outbox);

        $this->assertSame('needs_human', $conversa->fresh()->status);
    }

    public function test_reacao_nunca_e_respondida_nem_pela_chave_ligada_na_tela(): void
    {
        $conversa = $this->conversa();
        $msg = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'reaction', 'status' => 'received', 'payload' => ['reaction' => ['emoji' => '🙏', 'message_id' => 'wamid.x']]]);

        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldNotReceive('sendText');
        $this->rodar($conversa, $msg, $outbox);
    }

    public function test_reacao_logo_depois_da_pergunta_nao_cala_a_manuela(): void
    {
        $conversa = $this->conversa();
        $pergunta = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Qual o prazo de entrega?', 'status' => 'received']);
        $conversa->messages()->create(['direction' => 'inbound', 'type' => 'reaction', 'status' => 'received', 'payload' => ['reaction' => ['emoji' => '👍', 'message_id' => 'wamid.x']]]);

        $manuela = Mockery::mock(ManuelaAutoReplyService::class);
        $manuela->shouldReceive('buildReply')->once()->andReturn(['reply' => 'Chega até terça.', 'source' => 'gemini']);
        $outbox = Mockery::mock(WhatsAppOutbox::class);
        $outbox->shouldReceive('sendText')->once()->andReturn(new WhatsAppMessage);
        $this->rodar($conversa, $pergunta, $outbox, $manuela);
    }
}

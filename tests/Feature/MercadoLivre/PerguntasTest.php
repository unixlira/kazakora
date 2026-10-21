<?php

namespace Tests\Feature\MercadoLivre;

use App\Jobs\RascunharRespostaPerguntaMercadoLivre;
use App\Modules\Marketplace\Models\MercadoLivrePergunta;
use App\Services\MercadoLivre\MercadoLivreClient;
use App\Services\MercadoLivre\Services\QuestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PerguntasTest extends TestCase
{
    use RefreshDatabase;

    public function test_pergunta_sem_resposta_e_guardada_e_pede_rascunho(): void
    {
        Queue::fake();

        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->once()->with('questions/555', ['api_version' => 4])->andReturn([
            'id' => 555, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'text' => 'Tem garantia?',
            'from' => ['id' => 9], 'date_created' => '2026-10-20T17:31:00.000-03:00', 'answer' => null,
        ]);

        (new QuestionService($client))->processWebhook(['topic' => 'questions', 'resource' => '/questions/555']);

        $p = MercadoLivrePergunta::where('question_id', 555)->firstOrFail();
        $this->assertSame('UNANSWERED', $p->status);
        $this->assertSame('Tem garantia?', $p->pergunta);
        Queue::assertPushed(RascunharRespostaPerguntaMercadoLivre::class);
    }

    public function test_pergunta_ja_respondida_no_ml_nao_gera_rascunho(): void
    {
        Queue::fake();

        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->once()->andReturn([
            'id' => 556, 'item_id' => 'MLB1', 'status' => 'ANSWERED', 'text' => 'Voltagem?',
            'answer' => ['text' => 'Bivolt', 'date_created' => '2026-10-20T17:40:00.000-03:00'],
        ]);

        (new QuestionService($client))->processWebhook(['topic' => 'questions', 'resource' => '/questions/556']);

        $this->assertNotNull(MercadoLivrePergunta::where('question_id', 556)->first()->respondida_em);
        Queue::assertNotPushed(RascunharRespostaPerguntaMercadoLivre::class);
    }

    public function test_sem_gemini_a_pergunta_fica_marcada_e_o_admin_e_avisado(): void
    {
        config(['services.gemini.api_key' => null]);
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $p = MercadoLivrePergunta::create(['question_id' => 7, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'pergunta' => 'Serve no modelo X?']);

        $gemini = Mockery::mock(\App\Modules\WhatsApp\Services\GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(false);

        (new RascunharRespostaPerguntaMercadoLivre($p->id))->handle($gemini, Mockery::mock(MercadoLivreClient::class));

        $this->assertTrue($p->fresh()->precisa_humano);
        $this->assertNull($p->fresh()->resposta_enviada);
        $this->assertCount(1, $admin->notifications);
    }
}

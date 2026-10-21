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

    public function test_manuela_responde_sozinha_quando_ligado(): void
    {
        \App\Models\Setting::set(MercadoLivrePergunta::SETTING_AUTO, '1');
        $p = MercadoLivrePergunta::create(['question_id' => 8, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'pergunta' => 'É bivolt?']);

        $gemini = Mockery::mock(\App\Modules\WhatsApp\Services\GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generate')->andReturn('Olá! Sim, é bivolt. Qualquer dúvida, estamos à disposição!');

        $ml = Mockery::mock(MercadoLivreClient::class);
        $ml->shouldReceive('get')->with('items/MLB1')->andReturn(['title' => 'Parafusadeira', 'attributes' => [['name' => 'Voltagem', 'value_name' => 'Bivolt']]]);
        $ml->shouldReceive('get')->with('items/MLB1/description')->andReturn(['plain_text' => '']);
        $ml->shouldReceive('postOnce')->once()->with('answers', ['question_id' => 8, 'text' => 'Olá! Sim, é bivolt. Qualquer dúvida, estamos à disposição!'])->andReturn([]);
        $this->app->instance(MercadoLivreClient::class, $ml);

        (new RascunharRespostaPerguntaMercadoLivre($p->id))->handle($gemini, $ml);

        $p->refresh();
        $this->assertSame(MercadoLivrePergunta::POR_MANUELA, $p->respondida_por);
        $this->assertNotNull($p->respondida_em);
    }

    public function test_sem_automatico_fica_so_o_rascunho(): void
    {
        $p = MercadoLivrePergunta::create(['question_id' => 9, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'pergunta' => 'É bivolt?']);

        $gemini = Mockery::mock(\App\Modules\WhatsApp\Services\GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generate')->andReturn('Olá! Sim, é bivolt.');

        $ml = Mockery::mock(MercadoLivreClient::class);
        $ml->shouldReceive('get')->andReturn([]);
        $ml->shouldNotReceive('postOnce');
        $this->app->instance(MercadoLivreClient::class, $ml);

        (new RascunharRespostaPerguntaMercadoLivre($p->id))->handle($gemini, $ml);

        $this->assertSame('Olá! Sim, é bivolt.', $p->fresh()->resposta_rascunho);
        $this->assertNull($p->fresh()->respondida_em);
    }

    public function test_admin_assume_edita_e_envia_pela_tela(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $humano = MercadoLivrePergunta::create(['question_id' => 10, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'pergunta' => 'Serve no X?', 'precisa_humano' => true, 'rascunho_em' => now()]);
        MercadoLivrePergunta::create(['question_id' => 11, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'pergunta' => 'Cor?', 'resposta_rascunho' => 'Preta.', 'rascunho_em' => now()]);

        $this->actingAs($admin)->get('/admin/perguntas-marketplace')
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Admin/PerguntasMarketplace/Index', false)
                ->has('perguntas', 1)->where('contagem.humano', 1)->where('contagem.rascunho', 1));

        $this->actingAs($admin)->getJson('/admin/perguntas-marketplace/pendentes')->assertJson(['total' => 1]);
        $this->actingAs($admin)->get('/admin/perguntas-mercado-livre')->assertRedirect('/admin/perguntas-marketplace');

        $rascunho = MercadoLivrePergunta::where('question_id', 11)->first();
        $this->actingAs($admin)->postJson("/admin/perguntas-marketplace/{$rascunho->id}/assumir")->assertOk();
        $this->assertNotNull($rascunho->fresh()->humano_assumiu_em);
        $this->actingAs($admin)->getJson('/admin/perguntas-marketplace/pendentes')->assertJson(['total' => 2]);

        $ml = Mockery::mock(MercadoLivreClient::class);
        $ml->shouldReceive('postOnce')->once()->with('answers', ['question_id' => 10, 'text' => 'Serve sim.'])->andReturn([]);
        $this->app->instance(MercadoLivreClient::class, $ml);

        $this->actingAs($admin)->postJson("/admin/perguntas-marketplace/{$humano->id}/enviar", ['texto' => ' Serve sim. '])->assertOk();
        $humano->refresh();
        $this->assertSame(MercadoLivrePergunta::POR_HUMANO, $humano->respondida_por);
        $this->assertSame($admin->id, $humano->respondida_por_user_id);

        // Já respondida: não publica de novo.
        $this->actingAs($admin)->postJson("/admin/perguntas-marketplace/{$humano->id}/enviar", ['texto' => 'De novo'])->assertStatus(422);
    }

    public function test_devolver_para_a_manuela_pede_rascunho_novo(): void
    {
        Queue::fake();
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $p = MercadoLivrePergunta::create(['question_id' => 12, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'pergunta' => 'Garantia?', 'precisa_humano' => true, 'rascunho_em' => now(), 'humano_assumiu_em' => now(), 'humano_assumiu_por' => $admin->id]);

        $this->actingAs($admin)->postJson("/admin/perguntas-marketplace/{$p->id}/devolver")->assertOk();

        $this->assertFalse($p->fresh()->precisa_humano);
        $this->assertNull($p->fresh()->humano_assumiu_em);
        Queue::assertPushed(RascunharRespostaPerguntaMercadoLivre::class);
    }

    public function test_so_admin_liga_a_resposta_automatica(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson('/admin/perguntas-marketplace/auto-resposta', ['ligado' => true])->assertJson(['ligado' => true]);
        $this->assertTrue(MercadoLivrePergunta::manuelaRespondeSozinha());
    }

    public function test_aviso_em_tempo_real_traz_so_a_pergunta_nova_sem_dono(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        $inicio = $this->actingAs($admin)->getJson('/admin/perguntas-marketplace/chegando')->assertOk()->json();
        $this->assertSame([], $inicio['perguntas']);

        MercadoLivrePergunta::create(['question_id' => 21, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'pergunta' => 'Tem nota?', 'precisa_humano' => true, 'rascunho_em' => now()]);
        MercadoLivrePergunta::create(['question_id' => 22, 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'pergunta' => 'Já assumida', 'precisa_humano' => true, 'rascunho_em' => now(), 'humano_assumiu_em' => now(), 'humano_assumiu_por' => $admin->id]);

        $novas = $this->actingAs($admin)->getJson('/admin/perguntas-marketplace/chegando?desde='.urlencode($inicio['desde']))->assertOk()->json();

        $this->assertSame(2, $novas['total']);
        $this->assertCount(1, $novas['perguntas']);
        $this->assertSame('Tem nota?', $novas['perguntas'][0]['pergunta']);
    }
}

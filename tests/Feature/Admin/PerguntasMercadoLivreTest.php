<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MercadoLivrePergunta;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/** Atendimento > Perguntas Mercado Livre (pedido 2026-10-20). */
class PerguntasMercadoLivreTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function pergunta(array $extra = []): MercadoLivrePergunta
    {
        static $id = 100;

        return MercadoLivrePergunta::create([
            'question_id' => ++$id, 'item_id' => 'MLB123', 'status' => 'UNANSWERED',
            'pergunta' => 'É bivolt?', 'resposta_rascunho' => 'Olá! Sim, é bivolt.', 'rascunho_em' => now(),
            'perguntada_em' => now(), ...$extra,
        ]);
    }

    public function test_lista_pendentes_com_rascunho_e_contador_do_menu(): void
    {
        $this->pergunta();
        $this->pergunta(['respondida_em' => now(), 'status' => 'ANSWERED']);
        $this->pergunta(['status' => 'DELETED']);

        $this->actingAs($this->admin())->get('/admin/perguntas-mercado-livre')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/PerguntasMercadoLivre/Index', false)
                ->has('perguntas.data', 1)
                ->where('perguntas.data.0.resposta_rascunho', 'Olá! Sim, é bivolt.')
                ->where('perguntas.data.0.link_anuncio', 'https://produto.mercadolivre.com.br/MLB-123')
                ->where('pendentes', 1)
                ->where('sidebarBadges.mlPerguntasPendentes', 1));

        $this->actingAs($this->admin())->get('/admin/perguntas-mercado-livre?filtro=respondidas')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('perguntas.data', 1));
    }

    public function test_enviar_publica_a_resposta_editada_e_fecha_a_pergunta(): void
    {
        $p = $this->pergunta();

        $ml = Mockery::mock(MercadoLivreClient::class);
        $ml->shouldReceive('postOnce')->once()
            ->with('answers', ['question_id' => $p->question_id, 'text' => 'Olá! Sim, é bivolt de fábrica.'])
            ->andReturn(['id' => $p->question_id, 'status' => 'ANSWERED']);
        $this->app->instance(MercadoLivreClient::class, $ml);

        $this->actingAs($this->admin())
            ->post("/admin/perguntas-mercado-livre/{$p->id}/enviar", ['texto' => '  Olá! Sim, é bivolt de fábrica. '])
            ->assertSessionHas('success');

        $p->refresh();
        $this->assertSame('Olá! Sim, é bivolt de fábrica.', $p->resposta_enviada);
        $this->assertSame('ANSWERED', $p->status);
        $this->assertNotNull($p->respondida_em);
    }

    public function test_nao_envia_duas_vezes(): void
    {
        $p = $this->pergunta(['respondida_em' => now(), 'status' => 'ANSWERED']);

        $ml = Mockery::mock(MercadoLivreClient::class);
        $ml->shouldNotReceive('postOnce');
        $this->app->instance(MercadoLivreClient::class, $ml);

        $this->actingAs($this->admin())
            ->post("/admin/perguntas-mercado-livre/{$p->id}/enviar", ['texto' => 'Oi'])
            ->assertSessionHas('warning');
    }

    public function test_recusa_do_ml_mantem_a_pergunta_pendente(): void
    {
        $p = $this->pergunta();

        $ml = Mockery::mock(MercadoLivreClient::class);
        $ml->shouldReceive('postOnce')->once()->andThrow(new MercadoLivreException('Question already answered', 400));
        $this->app->instance(MercadoLivreClient::class, $ml);

        $this->actingAs($this->admin())
            ->post("/admin/perguntas-mercado-livre/{$p->id}/enviar", ['texto' => 'Oi'])
            ->assertSessionHas('error');

        $this->assertNull($p->fresh()->respondida_em);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Equipes\Models\Squad;
use App\Modules\Equipes\Models\SquadInstrucao;
use App\Modules\Equipes\Models\SquadMembro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equipes de agentes (pedido do Lira 2026-10-20): menu Equipes › Alpha Kora,
 * cards, cadastro, instruções extras e a API que os agentes leem.
 */
class EquipesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-da-equipe-teste';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.equipe_agentes.token' => self::TOKEN]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function membro(string $slug): SquadMembro
    {
        return SquadMembro::query()->where('slug', $slug)->firstOrFail();
    }

    public function test_migration_cria_alpha_kora_com_os_oito_membros(): void
    {
        $squad = Squad::query()->where('slug', 'alpha-kora')->firstOrFail();
        $this->assertSame('Alpha Kora', $squad->nome);
        $this->assertSame(1, Squad::query()->count());
        $this->assertEqualsCanonicalizing(
            ['caue-shopee', 'diego-mercado-livre', 'rafael-market-radar', 'jonathan-copy', 'paulo-dev', 'juliana-ops',
                'manuela-atendimento', 'giovanna-criacao'],
            $squad->membros()->pluck('slug')->all(),
        );
        $this->assertSame('Manuela', $this->membro('manuela-atendimento')->nome);
        $this->assertSame('Giovanna', $this->membro('giovanna-criacao')->nome);
        $this->assertSame('Juliana', $this->membro('giovanna-criacao')->respondePara?->nome);
        $this->assertSame('Juliana', $this->membro('jonathan-copy')->respondePara?->nome);
        $this->assertNull($this->membro('juliana-ops')->responde_para_id);
        $this->assertNull($this->membro('caue-shopee')->responde_para_id);
        $this->assertCount(5, $this->membro('paulo-dev')->precisa_ok);
    }

    public function test_tela_da_squad_e_menu_so_para_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/equipes/alpha-kora')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Equipes/Show', false)
            ->where('squad.slug', 'alpha-kora')
            ->has('membros', 8)
            ->where('equipesMenu.0.slug', 'alpha-kora'));

        $this->actingAs($admin)->get('/admin/equipes')->assertRedirect('/admin/equipes/alpha-kora');
        $this->actingAs($admin)->get('/admin/equipes/alpha-kora/membros/nova')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Equipes/Form', false)->where('membro', null));
        $this->actingAs($admin)->get('/admin/equipes/alpha-kora/membros/jonathan-copy/editar')->assertOk()
            ->assertInertia(fn ($page) => $page->where('membro.slug', 'jonathan-copy'));
        $this->actingAs($admin)->get('/admin/equipes/nao-existe')->assertNotFound();

        $staff = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->actingAs($staff)->get('/admin/equipes/alpha-kora')->assertForbidden();
    }

    public function test_adicionar_editar_e_desativar_membro(): void
    {
        $admin = $this->admin();
        $juliana = $this->membro('juliana-ops');

        $this->actingAs($admin)->post('/admin/equipes/alpha-kora/membros', [
            'nome' => 'Bia',
            'slug' => 'bia-atendimento',
            'funcao' => 'Atendimento de pós-venda',
            'area' => 'operacao',
            'o_que_faz' => 'Responde pós-venda.',
            'skills' => [['nome' => 'crm', 'descricao' => 'CRM'], ['nome' => '', 'descricao' => '']],
            'responde_para_id' => $juliana->id,
            'precisa_ok' => ['cliente', 'apagar'],
            'status' => 'pausado',
        ])->assertRedirect('/admin/equipes/alpha-kora?membro=bia-atendimento')
            ->assertSessionHas('success', 'Bia entrou na Alpha Kora.');

        $bia = $this->membro('bia-atendimento');
        $this->assertSame('pausado', $bia->status);
        $this->assertSame([['nome' => 'crm', 'descricao' => 'CRM']], $bia->skills);
        $this->assertSame($juliana->id, $bia->responde_para_id);

        // Slug repetido e fora do padrão são recusados.
        $this->actingAs($admin)->post('/admin/equipes/alpha-kora/membros', [
            'nome' => 'X', 'slug' => 'paulo-dev', 'funcao' => 'x', 'area' => 'dev', 'status' => 'ativo',
        ])->assertSessionHasErrors('slug');
        $this->actingAs($admin)->post('/admin/equipes/alpha-kora/membros', [
            'nome' => 'X', 'slug' => 'Com Espaço', 'funcao' => 'x', 'area' => 'dev', 'status' => 'ativo',
        ])->assertSessionHasErrors('slug');

        $this->actingAs($admin)->post('/admin/equipes/alpha-kora/membros', [
            'nome' => 'X', 'slug' => 'x-y', 'funcao' => 'x', 'area' => 'dev', 'status' => 'ativo',
            'skills' => [['nome' => 'a', 'descricao' => str_repeat('a', 2001)]],
        ])->assertSessionHasErrors('skills.0.descricao');

        $this->actingAs($admin)->put('/admin/equipes/alpha-kora/membros/bia-atendimento', [
            'nome' => 'Bia', 'slug' => 'bia-atendimento', 'funcao' => 'Pós-venda', 'area' => 'operacao',
            'status' => 'ativo', 'precisa_ok' => [],
        ])->assertRedirect();
        $this->assertSame('Pós-venda', $bia->fresh()->funcao);
        $this->assertSame([], $bia->fresh()->precisa_ok);

        $this->actingAs($admin)->patch('/admin/equipes/alpha-kora/membros/bia-atendimento/status', ['status' => 'desativado'])->assertRedirect();
        $this->assertSame('desativado', $bia->fresh()->status);
        $this->assertDatabaseHas('squad_membros', ['slug' => 'bia-atendimento']);
    }

    public function test_instrucoes_ligar_desligar_e_excluir_sem_apagar(): void
    {
        $admin = $this->admin();
        $url = '/admin/equipes/alpha-kora/membros/jonathan-copy/instrucoes';

        $this->actingAs($admin)->post($url, ['texto' => 'Comece a resposta com ALPHA KORA.'])
            ->assertSessionHas('success', 'Instrução salva. O Jonathan usa a partir da próxima tarefa.');
        $i = SquadInstrucao::query()->firstOrFail();
        $this->assertTrue($i->ativa);
        $this->assertSame($admin->id, $i->criada_por);

        $this->actingAs($admin)->patch("{$url}/{$i->id}", ['ativa' => false])->assertRedirect();
        $this->assertFalse($i->fresh()->ativa);

        // Instrução de outro membro não é alterada por este endereço.
        $this->actingAs($admin)->delete("/admin/equipes/alpha-kora/membros/paulo-dev/instrucoes/{$i->id}")->assertNotFound();

        $this->actingAs($admin)->delete("{$url}/{$i->id}")->assertRedirect();
        $this->assertSoftDeleted('squad_instrucoes', ['id' => $i->id]);
    }

    public function test_api_exige_token(): void
    {
        $this->getJson('/api/equipe/jonathan-copy/instrucoes')->assertUnauthorized();
        $this->getJson('/api/equipe/jonathan-copy/instrucoes', ['Authorization' => 'Bearer errado'])->assertUnauthorized();

        config(['services.equipe_agentes.token' => '']);
        $this->getJson('/api/equipe/jonathan-copy/instrucoes', ['Authorization' => 'Bearer '])->assertUnauthorized();
    }

    public function test_api_devolve_so_instrucoes_ativas_do_membro_ativo(): void
    {
        $jonathan = $this->membro('jonathan-copy');
        $ativa = $jonathan->instrucoes()->create(['texto' => 'Ativa 1', 'ativa' => true]);
        $jonathan->instrucoes()->create(['texto' => 'Desligada', 'ativa' => false]);
        $excluida = $jonathan->instrucoes()->create(['texto' => 'Excluída', 'ativa' => true]);
        $excluida->delete();
        $this->membro('paulo-dev')->instrucoes()->create(['texto' => 'Do Paulo', 'ativa' => true]);

        $h = ['Authorization' => 'Bearer '.self::TOKEN];
        $this->getJson('/api/equipe/jonathan-copy/instrucoes', $h)->assertOk()
            ->assertJsonPath('membro.status', 'ativo')
            ->assertJsonPath('membro.nome', 'Jonathan')
            ->assertJsonPath('membro.squad', 'Alpha Kora')
            ->assertJsonCount(1, 'instrucoes')
            ->assertJsonPath('instrucoes.0.id', $ativa->id)
            ->assertJsonPath('instrucoes.0.texto', 'Ativa 1')
            ->assertJsonPath('regra', fn ($r) => str_contains($r, 'OK do Lira'));

        $jonathan->update(['status' => 'pausado']);
        $this->getJson('/api/equipe/jonathan-copy/instrucoes', $h)->assertOk()
            ->assertJsonPath('membro.status', 'pausado')
            ->assertJsonCount(0, 'instrucoes');

        $jonathan->update(['status' => 'desativado']);
        $this->getJson('/api/equipe/jonathan-copy/instrucoes', $h)->assertOk()
            ->assertJsonPath('membro.status', 'desativado')
            ->assertJsonCount(0, 'instrucoes');

        $this->getJson('/api/equipe/ninguem/instrucoes', $h)->assertNotFound();
    }
}

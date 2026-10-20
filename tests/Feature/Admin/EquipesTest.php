<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Equipes\Models\Squad;
use App\Modules\Equipes\Models\SquadInstrucao;
use App\Modules\Equipes\Models\SquadMembro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equipes de agentes (pedido do Lira 2026-10-20): menu Equipes › Alfacora,
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

    public function test_migration_cria_alfacora_com_os_seis_membros(): void
    {
        $squad = Squad::query()->where('slug', 'alfacora')->firstOrFail();
        $this->assertSame('Alfacora', $squad->nome);
        $this->assertEqualsCanonicalizing(
            ['caue-shopee', 'diego-mercado-livre', 'rafael-market-radar', 'jonathan-copy', 'paulo-dev', 'juliana-ops'],
            $squad->membros()->pluck('slug')->all(),
        );
        $this->assertSame('Juliana', $this->membro('jonathan-copy')->respondePara?->nome);
        $this->assertNull($this->membro('juliana-ops')->responde_para_id);
        $this->assertNull($this->membro('caue-shopee')->responde_para_id);
        $this->assertCount(5, $this->membro('paulo-dev')->precisa_ok);
    }

    public function test_tela_da_squad_e_menu_so_para_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/equipes/alfacora')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Equipes/Show', false)
            ->where('squad.slug', 'alfacora')
            ->has('membros', 6)
            ->where('equipesMenu.0.slug', 'alfacora'));

        $this->actingAs($admin)->get('/admin/equipes')->assertRedirect('/admin/equipes/alfacora');
        $this->actingAs($admin)->get('/admin/equipes/alfacora/membros/nova')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Equipes/Form', false)->where('membro', null));
        $this->actingAs($admin)->get('/admin/equipes/alfacora/membros/jonathan-copy/editar')->assertOk()
            ->assertInertia(fn ($page) => $page->where('membro.slug', 'jonathan-copy'));
        $this->actingAs($admin)->get('/admin/equipes/nao-existe')->assertNotFound();

        $staff = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->actingAs($staff)->get('/admin/equipes/alfacora')->assertForbidden();
    }

    public function test_adicionar_editar_e_desativar_membro(): void
    {
        $admin = $this->admin();
        $juliana = $this->membro('juliana-ops');

        $this->actingAs($admin)->post('/admin/equipes/alfacora/membros', [
            'nome' => 'Bia',
            'slug' => 'bia-atendimento',
            'funcao' => 'Atendimento de pós-venda',
            'area' => 'operacao',
            'o_que_faz' => 'Responde pós-venda.',
            'skills' => [['nome' => 'crm', 'descricao' => 'CRM'], ['nome' => '', 'descricao' => '']],
            'responde_para_id' => $juliana->id,
            'precisa_ok' => ['cliente', 'apagar'],
            'status' => 'pausado',
        ])->assertRedirect('/admin/equipes/alfacora?membro=bia-atendimento')
            ->assertSessionHas('success', 'Bia entrou na Alfacora.');

        $bia = $this->membro('bia-atendimento');
        $this->assertSame('pausado', $bia->status);
        $this->assertSame([['nome' => 'crm', 'descricao' => 'CRM']], $bia->skills);
        $this->assertSame($juliana->id, $bia->responde_para_id);

        // Slug repetido e fora do padrão são recusados.
        $this->actingAs($admin)->post('/admin/equipes/alfacora/membros', [
            'nome' => 'X', 'slug' => 'paulo-dev', 'funcao' => 'x', 'area' => 'dev', 'status' => 'ativo',
        ])->assertSessionHasErrors('slug');
        $this->actingAs($admin)->post('/admin/equipes/alfacora/membros', [
            'nome' => 'X', 'slug' => 'Com Espaço', 'funcao' => 'x', 'area' => 'dev', 'status' => 'ativo',
        ])->assertSessionHasErrors('slug');

        $this->actingAs($admin)->post('/admin/equipes/alfacora/membros', [
            'nome' => 'X', 'slug' => 'x-y', 'funcao' => 'x', 'area' => 'dev', 'status' => 'ativo',
            'skills' => [['nome' => 'a', 'descricao' => str_repeat('a', 300)]],
        ])->assertSessionHasErrors('skills.0.descricao');

        $this->actingAs($admin)->put('/admin/equipes/alfacora/membros/bia-atendimento', [
            'nome' => 'Bia', 'slug' => 'bia-atendimento', 'funcao' => 'Pós-venda', 'area' => 'operacao',
            'status' => 'ativo', 'precisa_ok' => [],
        ])->assertRedirect();
        $this->assertSame('Pós-venda', $bia->fresh()->funcao);
        $this->assertSame([], $bia->fresh()->precisa_ok);

        $this->actingAs($admin)->patch('/admin/equipes/alfacora/membros/bia-atendimento/status', ['status' => 'desativado'])->assertRedirect();
        $this->assertSame('desativado', $bia->fresh()->status);
        $this->assertDatabaseHas('squad_membros', ['slug' => 'bia-atendimento']);
    }

    public function test_instrucoes_ligar_desligar_e_excluir_sem_apagar(): void
    {
        $admin = $this->admin();
        $url = '/admin/equipes/alfacora/membros/jonathan-copy/instrucoes';

        $this->actingAs($admin)->post($url, ['texto' => 'Comece a resposta com ALFACORA.'])
            ->assertSessionHas('success', 'Instrução salva. O Jonathan usa a partir da próxima tarefa.');
        $i = SquadInstrucao::query()->firstOrFail();
        $this->assertTrue($i->ativa);
        $this->assertSame($admin->id, $i->criada_por);

        $this->actingAs($admin)->patch("{$url}/{$i->id}", ['ativa' => false])->assertRedirect();
        $this->assertFalse($i->fresh()->ativa);

        // Instrução de outro membro não é alterada por este endereço.
        $this->actingAs($admin)->delete("/admin/equipes/alfacora/membros/paulo-dev/instrucoes/{$i->id}")->assertNotFound();

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
            ->assertJsonPath('membro.squad', 'Alfacora')
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

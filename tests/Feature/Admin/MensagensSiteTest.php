<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Contato\Models\MensagemContato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Admin > E-mails do site (pedido 2026-10-10). */
class MensagensSiteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function mensagem(array $extra = []): MensagemContato
    {
        return MensagemContato::create([
            'nome' => 'Maria', 'email' => 'maria@exemplo.com', 'assunto' => 'Prazo',
            'mensagem' => 'Chega até sexta?', ...$extra,
        ]);
    }

    public function test_caixa_lista_e_conta_nao_lidas(): void
    {
        $this->mensagem();
        $this->mensagem(['assunto' => 'Troca', 'lida_em' => now()]);

        $this->actingAs($this->admin())->get('/admin/mensagens-site')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/MensagensSite/Index', false)
                ->has('mensagens.data', 2)
                ->where('naoLidas', 1)
                ->where('sidebarBadges.emailsSiteNaoLidos', 1));

        $this->actingAs($this->admin())->get('/admin/mensagens-site?filtro=nao-lidas')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('mensagens.data', 1)->where('mensagens.data.0.assunto', 'Prazo'));
    }

    public function test_marcar_lida_e_nao_lida(): void
    {
        $mensagem = $this->mensagem();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/mensagens-site/{$mensagem->id}/lida")->assertRedirect();
        $this->assertNotNull($mensagem->fresh()->lida_em);

        $this->actingAs($admin)->post("/admin/mensagens-site/{$mensagem->id}/nao-lida")->assertRedirect();
        $this->assertNull($mensagem->fresh()->lida_em);
    }

    public function test_aviso_em_tempo_real_traz_so_as_novas(): void
    {
        $antiga = $this->mensagem();
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/mensagens-site/chegando')
            ->assertJson(['lastId' => $antiga->id, 'mensagens' => [], 'naoLidas' => 1]);

        $nova = $this->mensagem(['nome' => 'João', 'assunto' => 'Nota fiscal']);

        $this->actingAs($admin)->getJson("/admin/mensagens-site/chegando?after={$antiga->id}")
            ->assertJsonPath('lastId', $nova->id)
            ->assertJsonPath('naoLidas', 2)
            ->assertJsonCount(1, 'mensagens')
            ->assertJsonPath('mensagens.0.assunto', 'Nota fiscal');
    }

    public function test_cliente_nao_acessa(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($cliente)->get('/admin/mensagens-site')->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\AlertaAdminNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Tela da notificação para a equipe, aberta pelo sino do painel (2026-10-31). */
class NotificacoesEquipeTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipe_ve_o_texto_inteiro_no_painel_e_so_conta_como_lida_ao_abrir(): void
    {
        $texto = str_repeat('Detalhe do alerta. ', 20);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $admin->notify(new AlertaAdminNotification(1, 'Coleta falhou', $texto, '/admin/alertas'));
        $notificacao = $admin->notifications()->first();

        $this->actingAs($admin)->get('/notificacoes')->assertOk();
        $this->assertNull($notificacao->fresh()->read_at);

        $this->actingAs($admin)->get("/notificacoes/{$notificacao->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Notifications/ShowPainel', false)
                ->where('notificacao.titulo', 'Coleta falhou')
                ->where('notificacao.texto', $texto)
                ->where('notificacao.link', '/admin/alertas')
                ->where('notificacao.acao', 'Abrir'));

        $this->assertNotNull($notificacao->fresh()->read_at);
    }

    public function test_notificacao_sem_destino_abre_sem_botao(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $gerente->notify(new AlertaAdminNotification(1, 'Aviso', 'Só leitura', null));

        $this->actingAs($gerente)->get('/notificacoes/'.$gerente->notifications()->first()->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Notifications/ShowPainel', false)
                ->where('notificacao.link', null)
                ->where('notificacao.acao', null));
    }

    public function test_equipe_nao_abre_notificacao_de_outra_pessoa(): void
    {
        $dono = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $dono->notify(new AlertaAdminNotification(1, 'Aviso', 'Texto', null));
        $outro = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($outro)->get('/notificacoes/'.$dono->notifications()->first()->id)->assertNotFound();
        $this->assertNull($dono->notifications()->first()->read_at);
    }
}

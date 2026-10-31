<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\AlertaAdminNotification;
use App\Notifications\PrintJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_sino_mostra_alerta_interno_para_equipe_nas_telas_de_notificacao(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->alertaInterno($admin);
        $this->alertaInterno($admin);

        foreach (['/admin/fluxo-de-caixa', '/notificacoes'] as $url) {
            $this->actingAs($admin)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notifications.unreadCount', 2)
                ->has('notifications.items', 2));
        }

        $abrir = $admin->notifications()->first()->id;
        $this->actingAs($admin)->get("/notificacoes/{$abrir}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.unreadCount', 1)
            ->has('notifications.items', 1));
    }

    public function test_sino_segue_sem_alerta_interno_para_equipe_na_loja_e_para_cliente(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->alertaInterno($admin);
        $this->actingAs($admin)->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('notifications.unreadCount', 0));

        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->alertaInterno($cliente);
        $this->actingAs($cliente)->get('/notificacoes')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('notifications.unreadCount', 0));
    }

    private function alertaInterno(User $user): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => PrintJobFailedNotification::class,
            'data' => ['message' => 'Impressão falhou'],
        ]);
    }
}

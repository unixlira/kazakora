<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Acessos ao site na dashboard (pedido 2026-10-11). A conta tem que ser
 * de gente de fora: equipe e robô (aba presa recarregando /entrar 1.737
 * vezes num dia, visto em produção em 09/10) não entram.
 */
class DashboardVisitantesTest extends TestCase
{
    use RefreshDatabase;

    private function visita(string $visitante, string $quando, ?int $usuario = null, int $vezes = 1): void
    {
        for ($i = 0; $i < $vezes; $i++) {
            DB::table('site_visits')->insert([
                'visitor_id' => $visitante, 'user_id' => $usuario, 'path' => '/',
                'ip' => '1.1.1.1', 'consentiu' => true, 'created_at' => $quando,
            ]);
        }
    }

    public function test_conta_visitantes_do_mes_e_de_hoje_sem_equipe_nem_robo(): void
    {
        $this->travelTo('2026-10-11 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('ana', '2026-10-11 10:00:00', null, 3);
        $this->visita('ana', '2026-10-02 10:00:00');
        $this->visita('bia', '2026-10-11 11:00:00');
        // Navegador da equipe: sai inteiro, até a visita antes de logar.
        $this->visita('lira', '2026-10-11 09:00:00');
        $this->visita('lira', '2026-10-11 09:05:00', $admin->id);
        // Robô: mais de 300 páginas no dia.
        $this->visita('robo', '2026-10-11 12:00:00', null, 301);
        // Outro mês.
        $this->visita('caio', '2026-09-20 10:00:00');

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn ($page) => $page
            ->where('visitantes.mes', '2026-10')
            ->where('visitantes.visitantesHoje', 2)
            ->where('visitantes.paginasHoje', 4)
            ->where('visitantes.visitantesMes', 2)
            ->where('visitantes.paginasMes', 5)
            ->where('visitantes.meses', ['2026-10', '2026-09'])
            ->has('visitantes.dias', 11));
    }

    public function test_filtro_de_mes_mostra_o_mes_escolhido_inteiro(): void
    {
        $this->travelTo('2026-10-11 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->visita('caio', '2026-09-20 10:00:00', null, 2);

        $this->actingAs($admin)->get('/admin?mes=2026-09')->assertOk()->assertInertia(fn ($page) => $page
            ->where('visitantes.mes', '2026-09')
            ->where('visitantes.mesAtual', false)
            ->where('visitantes.visitantesHoje', null)
            ->where('visitantes.visitantesMes', 1)
            ->where('visitantes.paginasMes', 2)
            ->has('visitantes.dias', 30));
    }
}

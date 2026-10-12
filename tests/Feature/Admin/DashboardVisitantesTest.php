<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Acessos ao site na dashboard (pedido 2026-10-11). A conta tem que ser
 * de gente de fora: equipe e robô (aba presa recarregando /entrar 1.737
 * vezes num dia, visto em produção em 09/10) não entram, e a mesma
 * pessoa/IP não conta duas vezes (cookie primeiro, IP sem OK).
 */
class DashboardVisitantesTest extends TestCase
{
    use RefreshDatabase;

    private function visita(string $visitante, string $quando, ?int $usuario = null, int $vezes = 1, bool $consentiu = true, string $ip = '1.1.1.1'): void
    {
        for ($i = 0; $i < $vezes; $i++) {
            DB::table('site_visits')->insert([
                'visitor_id' => $visitante, 'user_id' => $usuario, 'path' => '/',
                'ip' => $ip, 'consentiu' => $consentiu, 'created_at' => $quando,
            ]);
        }
    }

    public function test_conta_visitantes_do_mes_e_de_hoje_sem_equipe_nem_robo(): void
    {
        $this->travelTo('2026-10-11 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('ana', '2026-10-11 10:00:00', null, 3, true, '1.1.1.1');
        $this->visita('ana', '2026-10-02 10:00:00', null, 1, true, '1.1.1.1');
        $this->visita('bia', '2026-10-11 11:00:00', null, 1, true, '2.2.2.2');
        // Cookie é o fator principal: outro cookie no mesmo IP da Bia é
        // outra pessoa.
        $this->visita('carla', '2026-10-11 11:30:00', null, 1, true, '2.2.2.2');
        // Navegador da equipe: sai inteiro, até a visita antes de logar e a
        // visita sem OK do mesmo IP no dia.
        $this->visita('lira', '2026-10-11 09:00:00', null, 1, true, '9.9.9.9');
        $this->visita('lira', '2026-10-11 09:05:00', $admin->id, 1, true, '9.9.9.9');
        $this->visita('s-lira', '2026-10-11 08:00:00', null, 1, false, '9.9.9.9');
        // Robô: mais de 300 páginas no dia.
        $this->visita('robo', '2026-10-11 12:00:00', null, 301, true, '8.8.8.8');
        // Outro mês.
        $this->visita('caio', '2026-09-20 10:00:00', null, 1, true, '3.3.3.3');
        // Sem OK de cookies (ids 10847/10848 em produção): cada sessão é um
        // código novo do mesmo IP. Conta um visitante pelo IP.
        $this->visita('s-sessao1', '2026-10-11 13:00:00', null, 1, false, '4.4.4.4');
        $this->visita('s-sessao2', '2026-10-11 13:00:00', null, 1, false, '4.4.4.4');
        // Navegou sem OK e depois deu OK: a mesma pessoa, vale o cookie.
        $this->visita('s-antes', '2026-10-11 14:00:00', null, 1, false, '5.5.5.5');
        $this->visita('duda', '2026-10-11 14:02:00', null, 1, true, '5.5.5.5');

        // Hoje: ana(3), bia, carla, IP 4.4.4.4 (2), duda (2) = 5 visitantes, 9 páginas.
        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn ($page) => $page
            ->where('visitantes.mes', '2026-10')
            ->where('visitantes.visitantesHoje', 5)
            ->where('visitantes.paginasHoje', 9)
            ->where('visitantes.visitantesMes', 5)
            ->where('visitantes.paginasMes', 10)
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

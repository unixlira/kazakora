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

    private function visita(string $visitante, string $quando, ?int $usuario = null, int $vezes = 1, bool $consentiu = true, string $ip = '1.1.1.1', string $site = 'kazakora'): void
    {
        for ($i = 0; $i < $vezes; $i++) {
            DB::table('site_visits')->insert([
                'site' => $site, 'visitor_id' => $visitante, 'user_id' => $usuario, 'path' => '/',
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
        $this->actingAs($admin)->get('/admin/indicadores?aba=acessos')->assertOk()->assertInertia(fn ($page) => $page
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

        $this->actingAs($admin)->get('/admin/indicadores?aba=acessos&mes=2026-09')->assertOk()->assertInertia(fn ($page) => $page
            ->where('visitantes.mes', '2026-09')
            ->where('visitantes.mesAtual', false)
            ->where('visitantes.visitantesHoje', null)
            ->where('visitantes.visitantesMes', 1)
            ->where('visitantes.paginasMes', 2)
            ->has('visitantes.dias', 30));
    }

    /**
     * Pedido 2026-10-22: o banco é compartilhado, então cada aba lê só o
     * próprio site (coluna site). Visitas KazaKora não conta FerraKora e
     * Visitas FerraKora não conta a loja.
     */
    public function test_cada_aba_conta_so_as_visitas_do_proprio_site(): void
    {
        $this->travelTo('2026-10-11 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('ana', '2026-10-11 10:00:00', null, 2, true, '1.1.1.1');
        // FerraKora não tem cookie: o visitante é o IP (md5), sem OK.
        $this->visita(md5('2.2.2.2'), '2026-10-11 11:00:00', null, 3, false, '2.2.2.2', 'ferrakora');
        $this->visita(md5('3.3.3.3'), '2026-10-11 12:00:00', null, 1, false, '3.3.3.3', 'ferrakora');
        $this->visita(md5('3.3.3.3'), '2026-09-30 12:00:00', null, 1, false, '3.3.3.3', 'ferrakora');

        $this->actingAs($admin)->get('/admin/indicadores?aba=acessos')->assertOk()->assertInertia(fn ($page) => $page
            ->where('aba', 'acessos')
            ->where('visitantes.site', 'kazakora')
            ->where('visitantes.visitantesHoje', 1)
            ->where('visitantes.paginasMes', 2)
            ->where('visitantes.meses', ['2026-10']));

        $this->actingAs($admin)->get('/admin/indicadores?aba=visitas-ferrakora')->assertOk()->assertInertia(fn ($page) => $page
            ->where('aba', 'visitas-ferrakora')
            ->where('visitantes.site', 'ferrakora')
            ->where('visitantes.visitantesHoje', 2)
            ->where('visitantes.visitantesMes', 2)
            ->where('visitantes.paginasMes', 4)
            ->where('visitantes.meses', ['2026-10', '2026-09']));

        $this->actingAs($admin)->get('/admin/indicadores?aba=visitas-ferrakora&mes=2026-09')->assertOk()->assertInertia(fn ($page) => $page
            ->where('visitantes.mes', '2026-09')
            ->where('visitantes.visitantesMes', 1));
    }
}

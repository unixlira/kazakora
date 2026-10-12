<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sistema › Visitas ao site (pedido 2026-10-12): log das páginas abertas na
 * loja com o IP. IP é dado pessoal, então a tela é só de admin, igual a
 * Logs. "Esconder equipe e robôs" segue a regra do painel.
 */
class SiteVisitLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function visita(string $visitante, string $quando, string $ip, string $path = '/', ?int $usuario = null, int $vezes = 1, bool $consentiu = true, ?string $userAgent = null): void
    {
        for ($i = 0; $i < $vezes; $i++) {
            DB::table('site_visits')->insert([
                'visitor_id' => $visitante, 'user_id' => $usuario, 'path' => $path,
                'ip' => $ip, 'consentiu' => $consentiu, 'created_at' => $quando,
                'user_agent' => $userAgent, 'dispositivo' => 'celular',
            ]);
        }
    }

    public function test_admin_ve_as_visitas_de_hoje_mais_recentes_primeiro(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Maria Cliente']);

        $this->visita('ana', '2026-10-12 10:00:00', '1.1.1.1', '/produto/caneca', null, 1, true,
            'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36');
        $this->visita('ana', '2026-10-12 11:00:00', '1.1.1.1', '/carrinho', $cliente->id);
        $this->visita('bia', '2026-10-12 12:00:00', '2.2.2.2', '/', null, 1, false);
        // Ontem: fora do padrão (hoje).
        $this->visita('caio', '2026-10-11 12:00:00', '3.3.3.3');

        $this->actingAs($admin)->get('/admin/visitas-site')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/VisitasSite/Index', false)
                ->has('visitas.data', 3)
                ->where('visitas.data.0.ip', '2.2.2.2')
                ->where('visitas.data.0.quando', '12/10/2026 12:00:00')
                ->where('visitas.data.0.navegador', null)
                ->where('visitas.data.1.cliente', 'Maria Cliente')
                ->where('visitas.data.2.navegador', 'Chrome · Android')
                ->where('visitas.data.2.path', '/produto/caneca')
                ->where('totais.visitas', 3)
                ->where('totais.ips', 2)
                ->where('filtros.de', '2026-10-12')
                ->where('filtros.ate', '2026-10-12'));
    }

    public function test_quem_nao_e_admin_nao_entra(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($gerente)->get('/admin/visitas-site')->assertForbidden();
        $this->actingAs($cliente)->get('/admin/visitas-site')->assertStatus(403);
        auth()->logout();
        $this->get('/admin/visitas-site')->assertRedirect();
    }

    public function test_filtro_por_ip_exato_e_por_comeco_do_ip_e_por_pagina(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('ana', '2026-10-12 10:00:00', '189.40.1.7', '/produto/caneca', null, 2);
        $this->visita('bia', '2026-10-12 10:00:00', '189.40.1.70', '/carrinho');
        $this->visita('caio', '2026-10-12 10:00:00', '10.0.0.1', '/produto/copo');

        // IP completo (o clique no IP): só ele, não o .70.
        $this->actingAs($admin)->get('/admin/visitas-site?ip=189.40.1.7')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('visitas.data', 2)
                ->where('visitas.data.0.ip', '189.40.1.7')
                ->where('totais.visitas', 2)
                ->where('totais.ips', 1));

        $this->actingAs($admin)->get('/admin/visitas-site?ip=189.40')
            ->assertInertia(fn ($page) => $page->where('totais.visitas', 3)->where('totais.ips', 2));

        $this->actingAs($admin)->get('/admin/visitas-site?pagina=produto')
            ->assertInertia(fn ($page) => $page->where('totais.visitas', 3)->where('totais.ips', 2));
    }

    public function test_esconde_equipe_e_robos_com_a_regra_do_painel(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('ana', '2026-10-12 10:00:00', '1.1.1.1');
        // Navegador da equipe: sai inteiro, inclusive antes de logar e a
        // visita sem OK do mesmo IP no dia.
        $this->visita('lira', '2026-10-12 09:00:00', '9.9.9.9');
        $this->visita('lira', '2026-10-12 09:05:00', '9.9.9.9', '/', $admin->id);
        $this->visita('s-lira', '2026-10-12 08:00:00', '9.9.9.9', '/', null, 1, false);
        // Robô: mais de 300 páginas no dia.
        $this->visita('robo', '2026-10-12 12:00:00', '8.8.8.8', '/entrar', null, 301);

        $this->actingAs($admin)->get('/admin/visitas-site')
            ->assertInertia(fn ($page) => $page
                ->where('totais.visitas', 1)
                ->where('totais.ips', 1)
                ->where('totais.escondidas', 304)
                ->where('visitas.data.0.ip', '1.1.1.1'));

        $this->actingAs($admin)->get('/admin/visitas-site?todos=1')
            ->assertInertia(fn ($page) => $page
                ->where('totais.visitas', 305)
                ->where('totais.ips', 3)
                ->where('visitas.total', 305)
                ->has('visitas.data', 50));
    }

    public function test_periodo_escolhido_e_visita_anonimizada_aparece_sem_ip(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('caio', '2026-10-05 12:00:00', '3.3.3.3');
        DB::table('site_visits')->insert([
            'visitor_id' => 'anonimo', 'path' => '/', 'ip' => null, 'consentiu' => false, 'created_at' => '2026-10-06 08:00:00',
        ]);

        $this->actingAs($admin)->get('/admin/visitas-site?de=2026-10-01&ate=2026-10-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('visitas.data', 2)
                ->where('visitas.data.0.ip', null)
                ->where('totais.visitas', 2)
                ->where('totais.ips', 1));

        $this->actingAs($admin)->get('/admin/visitas-site?de=12-10-2026')->assertSessionHasErrors('de');
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sistema › Visitas FerraKora e Visitas Beauty Kora (pedido 2026-10-12):
 * os sites avisam cada página em /api/{site}/visita, que grava em
 * site_visits com a coluna site, e a tela lista uma linha por IP com
 * Data/Hora, IP, Cliente e Ações (olho e lixeira).
 */
class OutroSiteVisitLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private function visita(string $ip, string $quando, string $path = '/', string $site = 'ferrakora'): void
    {
        DB::table('site_visits')->insert(['site' => $site, 'visitor_id' => md5($ip), 'ip' => $ip, 'path' => $path, 'created_at' => $quando]);
    }

    private function doSite(string $site)
    {
        return SiteVisit::query()->where('site', $site);
    }

    public function test_site_ferrakora_registra_visita_com_o_ip_do_visitante(): void
    {
        $this->withHeaders(['Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => self::NAVEGADOR])
            ->withServerVariables(['REMOTE_ADDR' => '189.40.12.7'])
            ->post('/api/ferrakora/visita?p=/produtos/furadeira')
            ->assertNoContent();

        $visita = SiteVisit::query()->sole();
        $this->assertSame('ferrakora', $visita->site);
        $this->assertSame('189.40.12.7', $visita->ip);
        $this->assertSame('/produtos/furadeira', $visita->path);
    }

    public function test_site_beautykora_grava_com_site_beautykora_e_so_do_seu_dominio(): void
    {
        $this->withHeaders(['Origin' => 'https://beautykora.devlira.com.br', 'User-Agent' => self::NAVEGADOR])
            ->post('/api/beautykora/visita?p=/produtos/serum')
            ->assertNoContent();

        // O domínio do FerraKora não avisa visita do Beauty Kora.
        $this->withHeaders(['Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => self::NAVEGADOR])
            ->post('/api/beautykora/visita?p=/')
            ->assertForbidden();

        $this->post('/api/outro/visita?p=/')->assertNotFound();

        $visita = SiteVisit::query()->sole();
        $this->assertSame('beautykora', $visita->site);
        $this->assertSame('/produtos/serum', $visita->path);
    }

    public function test_visitas_dos_outros_sites_ficam_fora_da_loja(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('5.5.5.5', '2026-10-12 10:00:00', '/', 'kazakora');
        $this->visita('6.6.6.6', '2026-10-12 10:00:00', '/', 'ferrakora');
        $this->visita('7.7.7.7', '2026-10-12 10:00:00', '/', 'beautykora');

        $this->actingAs($admin)->get('/admin/visitas-site?todos=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('visitas.data', 1)
                ->where('visitas.data.0.ip', '5.5.5.5'));

        $this->actingAs($admin)->get('/admin/visitas-beautykora')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/VisitasOutroSite/Index', false)
                ->where('site', 'beautykora')
                ->where('nome', 'BeautyKora')
                ->has('visitas.data', 1)
                ->where('visitas.data.0.ip', '7.7.7.7'));
    }

    public function test_recusa_outro_dominio_e_ignora_robo(): void
    {
        $this->withHeaders(['Origin' => 'https://outro-site.com', 'User-Agent' => self::NAVEGADOR])
            ->post('/api/ferrakora/visita?p=/')
            ->assertForbidden();

        $this->withHeaders(['Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])
            ->post('/api/ferrakora/visita?p=/')
            ->assertNoContent();

        $this->assertSame(0, SiteVisit::query()->count());
    }

    public function test_admin_ve_uma_linha_por_ip_e_cliente_pelo_login_no_kazakora(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Maria Cliente']);

        $this->visita('1.1.1.1', '2026-10-12 10:00:00');
        $this->visita('1.1.1.1', '2026-10-12 11:00:00', '/contato');
        $this->visita('2.2.2.2', '2026-10-12 12:00:00');
        $this->visita('3.3.3.3', '2026-10-11 12:00:00');
        // 1.1.1.1 entrou logada no kazakora.com; 2.2.2.2 é IP da equipe.
        DB::table('site_visits')->insert([
            ['visitor_id' => 'a', 'user_id' => $cliente->id, 'path' => '/', 'ip' => '1.1.1.1', 'consentiu' => true, 'created_at' => '2026-10-01 10:00:00'],
            ['visitor_id' => 'b', 'user_id' => $admin->id, 'path' => '/', 'ip' => '2.2.2.2', 'consentiu' => true, 'created_at' => '2026-10-01 10:00:00'],
        ]);

        $this->actingAs($admin)->get('/admin/visitas-ferrakora')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/VisitasOutroSite/Index', false)
                ->where('site', 'ferrakora')
                ->has('visitas.data', 2)
                ->where('visitas.data.0.ip', '2.2.2.2')
                ->where('visitas.data.0.cliente', null)
                ->where('visitas.data.1.ip', '1.1.1.1')
                ->where('visitas.data.1.quando', '12/10/2026 10:00:00')
                ->where('visitas.data.1.paginas', 2)
                ->where('visitas.data.1.cliente', 'Maria Cliente')
                ->where('totais.visitas', 3));

        $this->actingAs($admin)
            ->getJson('/admin/visitas-ferrakora/visitante?de=2026-10-12&ate=2026-10-12&ip=1.1.1.1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('cliente', 'Maria Cliente')
            ->assertJsonPath('visitas.0.quando', '12/10/2026 10:00:00');
    }

    public function test_lixeira_apaga_so_o_ip_no_periodo_e_registra_na_auditoria(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('1.1.1.1', '2026-10-12 10:00:00');
        $this->visita('1.1.1.1', '2026-10-11 10:00:00');
        $this->visita('2.2.2.2', '2026-10-12 10:00:00');
        $this->visita('1.1.1.1', '2026-10-12 10:00:00', '/', 'beautykora');

        $this->actingAs($admin)
            ->delete('/admin/visitas-ferrakora/visitante', ['de' => '2026-10-12', 'ate' => '2026-10-12', 'ip' => '1.1.1.1'])
            ->assertRedirect();

        // Mesmo IP no Beauty Kora não é apagado pela lixeira do FerraKora.
        $this->assertSame(2, $this->doSite('ferrakora')->count());
        $this->assertSame(1, $this->doSite('ferrakora')->where('ip', '1.1.1.1')->count());
        $this->assertSame(1, $this->doSite('beautykora')->count());
        $this->assertSame(1, AuditLog::query()->where('entity', 'SiteVisit')->count());
    }

    public function test_quem_nao_e_admin_nao_ve(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($gerente)->get('/admin/visitas-ferrakora')->assertForbidden();
    }

    public function test_olho_pagina_o_historico_de_100_em_100(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        for ($i = 0; $i < 150; $i++) {
            $this->visita('1.1.1.1', '2026-10-12 10:00:00', '/p'.$i);
        }

        $url = '/admin/visitas-ferrakora/visitante?de=2026-10-12&ate=2026-10-12&ip=1.1.1.1';
        $this->actingAs($admin)->getJson($url)
            ->assertOk()
            ->assertJsonPath('total', 150)
            ->assertJsonPath('paginas', 2)
            ->assertJsonCount(100, 'visitas');
        $this->actingAs($admin)->getJson($url.'&page=2')
            ->assertOk()
            ->assertJsonPath('pagina', 2)
            ->assertJsonCount(50, 'visitas');
    }
}

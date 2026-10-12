<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sistema › Visitas ao site (pedido 2026-10-12): uma linha por visitante (IP)
 * no período, com o histórico no olho e a lixeira que apaga as visitas do IP
 * no período. IP é dado pessoal, então a tela é só de admin, igual a Logs.
 * "Esconder equipe e robôs" segue a regra do painel.
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

    public function test_admin_ve_uma_linha_por_ip_com_o_primeiro_acesso_mais_recentes_primeiro(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Maria Cliente']);

        $this->visita('ana', '2026-10-12 10:00:00', '1.1.1.1', '/produto/caneca');
        $this->visita('ana', '2026-10-12 11:00:00', '1.1.1.1', '/carrinho', $cliente->id);
        // Outro cookie no mesmo IP continua na mesma linha (o IP é a chave).
        $this->visita('ana2', '2026-10-12 13:00:00', '1.1.1.1', '/');
        $this->visita('bia', '2026-10-12 12:00:00', '2.2.2.2', '/', null, 1, false);
        // Ontem: fora do padrão (hoje).
        $this->visita('caio', '2026-10-11 12:00:00', '3.3.3.3');

        $this->actingAs($admin)->get('/admin/visitas-site')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/VisitasSite/Index', false)
                ->has('visitas.data', 2)
                ->where('visitas.total', 2)
                ->where('visitas.data.0.ip', '2.2.2.2')
                ->where('visitas.data.0.quando', '12/10/2026 12:00:00')
                ->where('visitas.data.0.cliente', null)
                ->where('visitas.data.0.paginas', 1)
                ->where('visitas.data.1.ip', '1.1.1.1')
                ->where('visitas.data.1.quando', '12/10/2026 10:00:00')
                ->where('visitas.data.1.cliente', 'Maria Cliente')
                ->where('visitas.data.1.paginas', 3)
                ->where('visitas.data.1.apagaveis', 3)
                ->missing('visitas.data.0.path')
                ->where('totais.visitas', 4)
                ->where('totais.ips', 2)
                ->where('totais.visitantes', 2)
                ->where('filtros.de', '2026-10-12')
                ->where('filtros.ate', '2026-10-12'));
    }

    public function test_paginacao_conta_visitantes_e_nao_paginas(): void
    {
        $this->travelTo('2026-10-12 23:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        for ($i = 1; $i <= 55; $i++) {
            $this->visita('v'.$i, sprintf('2026-10-12 %02d:%02d:00', intdiv($i, 60) + 10, $i % 60), '10.0.0.'.$i, '/', null, 2);
        }

        $this->actingAs($admin)->get('/admin/visitas-site')
            ->assertInertia(fn ($page) => $page
                ->where('visitas.total', 55)
                ->where('visitas.last_page', 2)
                ->has('visitas.data', 50)
                ->where('visitas.data.0.ip', '10.0.0.55'));

        $this->actingAs($admin)->get('/admin/visitas-site?page=2')
            ->assertInertia(fn ($page) => $page
                ->has('visitas.data', 5)
                ->where('visitas.data.4.ip', '10.0.0.1'));
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
                ->has('visitas.data', 1)
                ->where('visitas.data.0.paginas', 2)
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
                ->where('visitas.total', 3)
                ->has('visitas.data', 3));
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
                ->where('visitas.data.0.visitor_id', 'anonimo')
                ->where('totais.visitas', 2)
                ->where('totais.ips', 1));

        $this->actingAs($admin)->get('/admin/visitas-site?de=12-10-2026')->assertSessionHasErrors('de');
    }

    public function test_anonimizadas_agrupam_pelo_visitor_id_e_o_detalhe_abre(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach ([['velho1', '2026-10-06 08:00:00', '/'], ['velho1', '2026-10-06 08:05:00', '/carrinho'], ['velho2', '2026-10-06 09:00:00', '/']] as [$v, $quando, $path]) {
            DB::table('site_visits')->insert(['visitor_id' => $v, 'path' => $path, 'ip' => null, 'consentiu' => false, 'created_at' => $quando]);
        }

        $this->actingAs($admin)->get('/admin/visitas-site?de=2026-10-06&ate=2026-10-06')
            ->assertInertia(fn ($page) => $page
                ->where('visitas.total', 2)
                ->where('visitas.data.0.visitor_id', 'velho2')
                ->where('visitas.data.1.visitor_id', 'velho1')
                ->where('visitas.data.1.paginas', 2));

        $this->actingAs($admin)->getJson('/admin/visitas-site/visitante?de=2026-10-06&ate=2026-10-06&visitor_id=velho1')
            ->assertOk()
            ->assertJsonPath('ip', null)
            ->assertJsonPath('visitor_id', 'velho1')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('visitas.0.path', '/')
            ->assertJsonPath('visitas.1.path', '/carrinho');
    }

    public function test_detalhe_lista_as_paginas_do_ip_no_periodo_em_ordem_de_horario(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Maria Cliente']);

        $this->visita('ana', '2026-10-12 11:00:00', '1.1.1.1', '/carrinho', $cliente->id, 1, true,
            'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36');
        $this->visita('ana', '2026-10-12 10:00:00', '1.1.1.1', '/produto/caneca');
        DB::table('site_visits')->where('path', '/produto/caneca')->update(['utm_source' => 'instagram', 'utm_medium' => 'bio']);
        $this->visita('ana', '2026-10-12 10:30:00', '1.1.1.1', '/busca');
        DB::table('site_visits')->where('path', '/busca')->update(['referer' => 'https://www.google.com/search?q=x']);
        // Fora do período e outro IP: não entram.
        $this->visita('ana', '2026-10-11 10:00:00', '1.1.1.1', '/ontem');
        $this->visita('bia', '2026-10-12 10:10:00', '2.2.2.2', '/outro');

        $this->actingAs($admin)->getJson('/admin/visitas-site/visitante?de=2026-10-12&ate=2026-10-12&ip=1.1.1.1')
            ->assertOk()
            ->assertJsonPath('ip', '1.1.1.1')
            ->assertJsonPath('total', 3)
            ->assertJsonCount(3, 'visitas')
            ->assertJsonPath('visitas.0.path', '/produto/caneca')
            ->assertJsonPath('visitas.0.quando', '12/10/2026 10:00:00')
            ->assertJsonPath('visitas.0.origem.rotulo', 'Instagram')
            ->assertJsonPath('visitas.0.origem.utm_medium', 'bio')
            ->assertJsonPath('visitas.0.dispositivo', 'celular')
            ->assertJsonPath('visitas.1.origem.rotulo', 'Google')
            ->assertJsonPath('visitas.1.origem.referer', 'https://www.google.com/search?q=x')
            ->assertJsonPath('visitas.2.origem.rotulo', 'Sem dado')
            ->assertJsonPath('visitas.2.path', '/carrinho')
            ->assertJsonPath('visitas.2.cliente', 'Maria Cliente')
            ->assertJsonPath('visitas.2.navegador', 'Chrome · Android');

        // Sem IP nem visitor_id não abre nada (o admin não renderiza erro em JSON).
        $this->actingAs($admin)->get('/admin/visitas-site/visitante?de=2026-10-12&ate=2026-10-12')
            ->assertSessionHasErrors(['ip', 'visitor_id']);
    }

    public function test_lixeira_apaga_so_o_ip_dentro_do_periodo_e_registra_quem_apagou(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Dentro do período (10 e 11/10): 3 do IP, uma delas de outro cookie.
        $this->visita('ana', '2026-10-10 00:00:00', '1.1.1.1', '/');
        $this->visita('ana', '2026-10-11 23:59:59', '1.1.1.1', '/carrinho');
        $this->visita('ana2', '2026-10-11 12:00:00', '1.1.1.1', '/');
        // Mesmo IP fora do período: ficam.
        $this->visita('ana', '2026-10-09 23:59:59', '1.1.1.1', '/antes');
        $this->visita('ana', '2026-10-12 00:00:00', '1.1.1.1', '/depois');
        // Outros IPs (inclusive um que começa igual) e anonimizada: ficam.
        $this->visita('bia', '2026-10-11 10:00:00', '2.2.2.2');
        $this->visita('caio', '2026-10-11 10:00:00', '1.1.1.10');
        DB::table('site_visits')->insert(['visitor_id' => 'ana', 'path' => '/', 'ip' => null, 'consentiu' => false, 'created_at' => '2026-10-11 10:00:00']);

        $this->actingAs($admin)->get('/admin/visitas-site?de=2026-10-10&ate=2026-10-11&ip=1.1.1.1')
            ->assertInertia(fn ($page) => $page->where('visitas.data.0.apagaveis', 3));

        $this->actingAs($admin)
            ->from('/admin/visitas-site?de=2026-10-10&ate=2026-10-11')
            ->delete('/admin/visitas-site/visitante', ['de' => '2026-10-10', 'ate' => '2026-10-11', 'ip' => '1.1.1.1'])
            ->assertRedirect('/admin/visitas-site?de=2026-10-10&ate=2026-10-11')
            ->assertSessionHas('success', '3 visitas do IP 1.1.1.1 apagadas (10/10 a 11/10).');

        $this->assertSame(0, DB::table('site_visits')->where('ip', '1.1.1.1')->whereBetween('created_at', ['2026-10-10 00:00:00', '2026-10-11 23:59:59'])->count());
        $this->assertSame(['/antes', '/depois'], DB::table('site_visits')->where('ip', '1.1.1.1')->orderBy('created_at')->pluck('path')->all());
        $this->assertSame(1, DB::table('site_visits')->where('ip', '2.2.2.2')->count());
        $this->assertSame(1, DB::table('site_visits')->where('ip', '1.1.1.10')->count());
        $this->assertSame(1, DB::table('site_visits')->whereNull('ip')->count());

        $log = AuditLog::query()->where('entity', 'SiteVisit')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(AuditLog::ACTION_DELETE, $log->action);
        $this->assertSame(['ip' => '1.1.1.1', 'visitor_id' => null, 'de' => '2026-10-10', 'ate' => '2026-10-11', 'apagadas' => 3], $log->old_values);
    }

    public function test_lixeira_de_anonimizada_apaga_so_as_sem_ip_daquele_visitor_id(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach ([['velho1', null, '2026-10-06 08:00:00'], ['velho1', null, '2026-10-06 09:00:00'], ['velho1', null, '2026-10-01 09:00:00'], ['velho2', null, '2026-10-06 09:00:00'], ['velho1', '5.5.5.5', '2026-10-06 09:00:00']] as [$v, $ip, $quando]) {
            DB::table('site_visits')->insert(['visitor_id' => $v, 'path' => '/', 'ip' => $ip, 'consentiu' => false, 'created_at' => $quando]);
        }

        $this->actingAs($admin)
            ->delete('/admin/visitas-site/visitante', ['de' => '2026-10-06', 'ate' => '2026-10-06', 'visitor_id' => 'velho1'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('site_visits')->where('visitor_id', 'velho1')->whereNull('ip')->where('created_at', '>=', '2026-10-06')->count());
        $this->assertSame(3, DB::table('site_visits')->count());
    }

    public function test_quem_nao_e_admin_nao_ve_o_detalhe_nem_apaga(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->visita('ana', '2026-10-12 10:00:00', '1.1.1.1', '/', null, 2);
        $alvo = ['de' => '2026-10-12', 'ate' => '2026-10-12', 'ip' => '1.1.1.1'];

        $this->actingAs($gerente)->getJson('/admin/visitas-site/visitante?'.http_build_query($alvo))->assertForbidden();
        $this->actingAs($gerente)->delete('/admin/visitas-site/visitante', $alvo)->assertForbidden();
        $this->actingAs($cliente)->delete('/admin/visitas-site/visitante', $alvo)->assertStatus(403);
        auth()->logout();
        $this->delete('/admin/visitas-site/visitante', $alvo)->assertRedirect();

        $this->assertSame(2, DB::table('site_visits')->count());
        $this->assertSame(0, AuditLog::query()->where('entity', 'SiteVisit')->count());
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Analytics\Models\FerraKoraVisit;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sistema › Visitas FerraKora (pedido 2026-10-12): o site FerraKora avisa
 * cada página em /api/ferrakora/visita e a tela lista uma linha por IP com
 * Data/Hora, IP, Cliente e Ações (olho e lixeira).
 */
class FerraKoraVisitLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private function visita(string $ip, string $quando, string $path = '/'): void
    {
        DB::table('ferrakora_visits')->insert(['ip' => $ip, 'path' => $path, 'created_at' => $quando]);
    }

    public function test_site_ferrakora_registra_visita_com_o_ip_do_visitante(): void
    {
        $this->withHeaders(['Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => self::NAVEGADOR])
            ->withServerVariables(['REMOTE_ADDR' => '189.40.12.7'])
            ->post('/api/ferrakora/visita?p=/produtos/furadeira')
            ->assertNoContent();

        $visita = FerraKoraVisit::query()->sole();
        $this->assertSame('189.40.12.7', $visita->ip);
        $this->assertSame('/produtos/furadeira', $visita->path);
    }

    public function test_recusa_outro_dominio_e_ignora_robo(): void
    {
        $this->withHeaders(['Origin' => 'https://outro-site.com', 'User-Agent' => self::NAVEGADOR])
            ->post('/api/ferrakora/visita?p=/')
            ->assertForbidden();

        $this->withHeaders(['Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])
            ->post('/api/ferrakora/visita?p=/')
            ->assertNoContent();

        $this->assertSame(0, FerraKoraVisit::query()->count());
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
                ->component('Admin/VisitasFerraKora/Index', false)
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

        $this->actingAs($admin)
            ->delete('/admin/visitas-ferrakora/visitante', ['de' => '2026-10-12', 'ate' => '2026-10-12', 'ip' => '1.1.1.1'])
            ->assertRedirect();

        $this->assertSame(2, FerraKoraVisit::query()->count());
        $this->assertSame(1, FerraKoraVisit::query()->where('ip', '1.1.1.1')->count());
        $this->assertSame(1, AuditLog::query()->where('entity', 'FerraKoraVisit')->count());
    }

    public function test_quem_nao_e_admin_nao_ve(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($gerente)->get('/admin/visitas-ferrakora')->assertForbidden();
    }
}

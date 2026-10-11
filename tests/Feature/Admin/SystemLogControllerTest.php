<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu "Log" (pedido explícito 2026-09-14). O que mais importa aqui é o
 * fechamento: log de produção tem dado de cliente e payload de marketplace,
 * então a tela é só de admin e o nome do arquivo que vem pela query string
 * nunca pode sair de storage/logs.
 */
class SystemLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/kazakora-logs-feature-'.uniqid();
        mkdir($this->base.'/logs', 0777, true);
        $this->app->useStoragePath($this->base);

        file_put_contents($this->base.'/logs/laravel-2026-09-13.log', implode('', [
            "[2026-09-13 08:00:00] production.INFO: importou o pedido 2028\n",
            "[2026-09-13 09:00:00] production.ERROR: nfe.issue.failed {\"order_id\":2028}\n",
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base.'/logs/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->base.'/logs');
        @rmdir($this->base);

        parent::tearDown();
    }

    public function test_admin_ve_a_lista_com_os_filtros_aplicados(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/logs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // false: este projeto não usa resource_path('js/Pages'),
                // então a checagem de existência do Inertia não se aplica.
                ->component('Admin/Logs/Index', false)
                ->has('logs', 2)
                ->has('arquivos', 1)
                ->where('logs.0.nivel', 'error')
                ->where('paginacao.temProxima', false));

        $this->actingAs($admin)->get('/admin/logs?niveis[]=error')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs', 1));

        $this->actingAs($admin)->get('/admin/logs?q=importou')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs', 1)
                ->where('logs.0.nivel', 'info'));
    }

    public function test_nivel_invalido_e_recusado_e_arquivo_de_fora_e_ignorado(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/logs?niveis[]=tudo')->assertSessionHasErrors('niveis.0');

        // Nome fora da lista de storage/logs não vira leitura de arquivo
        // nenhum — cai no "todos os arquivos", não em ../../.env.
        $this->actingAs($admin)->get('/admin/logs?arquivo='.urlencode('../../.env'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filtros.arquivo', null)->has('logs', 2));
    }

    public function test_gerente_nao_entra(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_MANAGER]))
            ->get('/admin/logs')->assertForbidden();
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/admin/logs')->assertRedirect('/entrar');
    }
}

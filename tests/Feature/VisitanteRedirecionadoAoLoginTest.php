<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VisitanteRedirecionadoAoLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_teste/proibido', fn () => abort(403));
        Route::middleware(['web', 'signed'])->get('/_teste/assinado', fn () => 'ok');
    }

    public function test_visitante_deslogado_vai_para_o_login_em_vez_do_403(): void
    {
        $this->get('/_teste/proibido')->assertRedirect(route('entrar'));

        $this->assertSame(url('/_teste/proibido'), session('url.intended'));
    }

    public function test_visitante_no_admin_vai_para_o_login(): void
    {
        $this->get('/admin')->assertRedirect(route('entrar'));
    }

    public function test_usuario_logado_sem_permissao_continua_vendo_o_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]));

        $this->get('/_teste/proibido')->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_chamada_json_continua_recebendo_403(): void
    {
        $this->getJson('/_teste/proibido')->assertForbidden();
    }

    public function test_link_assinado_invalido_continua_403(): void
    {
        $this->get('/_teste/assinado?signature=invalida')->assertForbidden();
    }
}

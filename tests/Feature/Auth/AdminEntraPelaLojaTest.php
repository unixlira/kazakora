<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Em kazakora.com o nginx devolve 404 para /admin (o painel é
 * painel.kazakora.com): admin que entra pela loja vai para o painel já logado.
 */
class AdminEntraPelaLojaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_que_entra_pela_loja_vai_para_o_painel(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $resposta = $this->post('https://kazakora.com/entrar', ['email' => $admin->email, 'password' => 'password']);

        $destino = $resposta->headers->get('Location');
        $this->assertStringStartsWith('https://painel.kazakora.com/entrar/painel/'.$admin->id.'?', $destino);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get($destino)->assertRedirect('https://painel.kazakora.com/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_pelo_inertia_sai_da_loja_com_location(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->withHeaders(['X-Inertia' => 'true'])
            ->post('https://kazakora.com/entrar', ['email' => $admin->email, 'password' => 'password'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');
    }

    public function test_link_do_painel_vale_uma_vez_so(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $link = 'https://painel.kazakora.com'.URL::temporarySignedRoute('entrar.painel', now()->addMinute(), ['user' => $admin->id], absolute: false);

        $this->get($link)->assertRedirect('https://painel.kazakora.com/admin');

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get($link)->assertRedirect('https://painel.kazakora.com/entrar');
        $this->assertGuest();
    }

    public function test_link_sem_assinatura_ou_de_cliente_nao_entra(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $cliente = User::factory()->create();

        $this->get('https://painel.kazakora.com/entrar/painel/'.$admin->id)->assertForbidden();

        $link = 'https://painel.kazakora.com'.URL::temporarySignedRoute('entrar.painel', now()->addMinute(), ['user' => $cliente->id], absolute: false);
        $this->get($link)->assertRedirect('https://painel.kazakora.com/entrar');
        $this->assertGuest();
    }

    public function test_cliente_que_entra_pela_loja_fica_na_loja(): void
    {
        $cliente = User::factory()->create();

        $this->post('https://kazakora.com/entrar', ['email' => $cliente->email, 'password' => 'password'])
            ->assertRedirect('https://kazakora.com');
    }

    public function test_admin_que_entra_pelo_painel_vai_para_o_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->post('https://painel.kazakora.com/entrar', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('https://painel.kazakora.com/admin');
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Em kazakora.com o nginx devolve 404 para /admin (o painel é
 * painel.kazakora.com): admin que entra pela loja fica na home.
 */
class AdminEntraPelaLojaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_que_entra_pela_loja_fica_na_home(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->post('https://kazakora.com/entrar', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('https://kazakora.com');
    }

    public function test_admin_que_entra_pelo_painel_vai_para_o_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->post('https://painel.kazakora.com/entrar', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('https://painel.kazakora.com/admin');
    }
}

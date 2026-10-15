<?php

namespace Tests\Feature\FerraKora;

use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conta do site FerraKora (pedido 2026-10-14): o menu de conta do topo faz
 * cadastro, login e perfil por /api/ferrakora/*.
 */
class FerraKoraContaTest extends TestCase
{
    use RefreshDatabase;

    private function cadastrar(string $email = 'joao@exemplo.com'): string
    {
        return $this->postJson('/api/ferrakora/cadastro', ['name' => 'João', 'email' => $email, 'password' => 'segredo123'])
            ->assertCreated()
            ->assertJsonPath('user.email', $email)
            ->json('token');
    }

    public function test_cadastro_login_eu_e_atualizar(): void
    {
        $this->cadastrar();

        $this->postJson('/api/ferrakora/login', ['email' => 'JOAO@exemplo.com', 'password' => 'errada00'])
            ->assertStatus(422);

        $token = $this->postJson('/api/ferrakora/login', ['email' => 'JOAO@exemplo.com', 'password' => 'segredo123'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/ferrakora/eu')
            ->assertOk()->assertJsonPath('user.name', 'João')->assertJsonMissingPath('user.password');

        $this->withToken($token)->patchJson('/api/ferrakora/eu', ['name' => 'João Silva'])
            ->assertOk()->assertJsonPath('user.name', 'João Silva');

        $this->assertDatabaseMissing('users', ['email' => 'joao@exemplo.com']);
    }

    public function test_email_repetido_e_senha_curta_sao_recusados(): void
    {
        $this->cadastrar();

        $this->postJson('/api/ferrakora/cadastro', ['name' => 'Outro', 'email' => 'joao@exemplo.com', 'password' => 'segredo123'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/ferrakora/cadastro', ['name' => 'Outro', 'email' => 'outro@exemplo.com', 'password' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_sem_token_ou_com_token_do_beauty_kora_nao_entra(): void
    {
        $this->getJson('/api/ferrakora/eu')->assertUnauthorized();

        $daBeauty = BeautyKoraCliente::query()->create(['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'segredo123']);
        $tokenBeauty = $daBeauty->createToken('teste')->plainTextToken;
        $this->withToken($tokenBeauty)->getJson('/api/ferrakora/eu')->assertUnauthorized();
    }

    public function test_sair_invalida_o_token(): void
    {
        $token = $this->cadastrar();

        $this->withToken($token)->postJson('/api/ferrakora/sair')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/ferrakora/eu')->assertUnauthorized();
    }
}

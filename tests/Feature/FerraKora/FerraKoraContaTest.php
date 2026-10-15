<?php

namespace Tests\Feature\FerraKora;

use App\Models\User;
use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraSessao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Conta do site FerraKora (pedido 2026-10-14): login com a conta da KazaKora
 * (tabela users) pelo menu de conta do topo, /api/ferrakora/*.
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

    public function test_cadastro_cria_conta_da_kazakora_e_login_eu_e_atualizar(): void
    {
        $this->cadastrar();

        $this->assertDatabaseHas('users', ['email' => 'joao@exemplo.com', 'role' => User::ROLE_CUSTOMER]);

        $this->postJson('/api/ferrakora/login', ['email' => 'joao@exemplo.com', 'password' => 'errada00'])
            ->assertStatus(422);

        $token = $this->postJson('/api/ferrakora/login', ['email' => 'joao@exemplo.com', 'password' => 'segredo123'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/ferrakora/eu')
            ->assertOk()->assertJsonPath('user.name', 'João')->assertJsonMissingPath('user.password');

        $this->withToken($token)->patchJson('/api/ferrakora/eu', ['name' => 'João Silva'])
            ->assertOk()->assertJsonPath('user.name', 'João Silva');

        // O banco guarda só o hash do token.
        $this->assertDatabaseMissing('ferrakora_sessoes', ['token_hash' => $token]);
        $this->assertDatabaseHas('ferrakora_sessoes', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_quem_ja_tem_conta_na_kazakora_entra_com_a_mesma_senha(): void
    {
        User::factory()->create(['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'senhaKazaKora1', 'role' => User::ROLE_CUSTOMER]);

        $this->postJson('/api/ferrakora/cadastro', ['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'outrasenha1'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/ferrakora/login', ['email' => 'maria@exemplo.com', 'password' => 'senhaKazaKora1'])
            ->assertOk()->assertJsonPath('user.name', 'Maria');
    }

    public function test_senha_curta_e_conta_apagada_sao_recusadas(): void
    {
        $this->postJson('/api/ferrakora/cadastro', ['name' => 'Outro', 'email' => 'outro@exemplo.com', 'password' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $apagada = User::factory()->create(['email' => 'apagada@exemplo.com', 'password' => 'segredo123']);
        $apagada->delete();
        $this->postJson('/api/ferrakora/login', ['email' => 'apagada@exemplo.com', 'password' => 'segredo123'])->assertStatus(422);
        $this->postJson('/api/ferrakora/cadastro', ['name' => 'Nova', 'email' => 'apagada@exemplo.com', 'password' => 'segredo123'])->assertStatus(422);
    }

    public function test_sem_token_com_token_errado_ou_do_beauty_kora_nao_entra(): void
    {
        $this->getJson('/api/ferrakora/eu')->assertUnauthorized();
        $this->withToken('qualquer-coisa')->getJson('/api/ferrakora/eu')->assertUnauthorized();

        $daBeauty = BeautyKoraCliente::query()->create(['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'segredo123']);
        $tokenBeauty = $daBeauty->createToken('teste')->plainTextToken;
        $this->withToken($tokenBeauty)->getJson('/api/ferrakora/eu')->assertUnauthorized();
    }

    public function test_sair_e_sessao_vencida_invalidam_o_token(): void
    {
        $token = $this->cadastrar();

        $this->withToken($token)->postJson('/api/ferrakora/sair')->assertOk();
        $this->withToken($token)->getJson('/api/ferrakora/eu')->assertUnauthorized();

        $outro = $this->cadastrar('ana@exemplo.com');
        FerraKoraSessao::query()->where('token_hash', hash('sha256', $outro))->update(['expira_em' => now()->subMinute()]);
        $this->withToken($outro)->getJson('/api/ferrakora/eu')->assertUnauthorized();
    }

    public function test_uso_empurra_o_vencimento(): void
    {
        $token = $this->cadastrar();
        FerraKoraSessao::query()->update(['expira_em' => now()->addDay(), 'ultimo_uso_em' => now()->subHour()]);

        $this->withToken($token)->getJson('/api/ferrakora/eu')->assertOk();

        $this->assertTrue(FerraKoraSessao::query()->firstOrFail()->expira_em->gt(now()->addDays(29)));
    }

    public function test_esqueci_a_senha_manda_o_link_da_kazakora(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'maria@exemplo.com']);

        $this->postJson('/api/ferrakora/esqueci-senha', ['email' => 'maria@exemplo.com'])->assertOk();
        $this->postJson('/api/ferrakora/esqueci-senha', ['email' => 'ninguem@exemplo.com'])->assertOk();

        Mail::assertSent(\App\Modules\Auth\Mail\PasswordResetMail::class, 1);
    }
}

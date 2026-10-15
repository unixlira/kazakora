<?php

namespace Tests\Feature\FerraKora;

use App\Models\User;
use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\FerraKora\Mail\FerraKoraNovaSenha;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraSessao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Conta do site FerraKora (pedido 2026-10-14) pelo menu de conta do topo,
 * /api/ferrakora/*. Separada da KazaKora desde 2026-10-15 (ferrakora_clientes).
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

    public function test_cadastro_cria_conta_do_ferrakora_e_login_eu_e_atualizar(): void
    {
        $this->cadastrar();

        $this->assertDatabaseHas('ferrakora_clientes', ['email' => 'joao@exemplo.com', 'senha_definida' => true]);
        $this->assertSame(0, User::count());

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

    public function test_conta_da_kazakora_nao_entra_no_ferrakora(): void
    {
        User::factory()->create(['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'senhaKazaKora1', 'role' => User::ROLE_CUSTOMER]);

        $this->postJson('/api/ferrakora/login', ['email' => 'maria@exemplo.com', 'password' => 'senhaKazaKora1'])->assertStatus(422);

        // O mesmo e-mail cria conta própria no FerraKora; a da KazaKora fica intacta.
        $this->cadastrar('maria@exemplo.com');
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('ferrakora_clientes', ['email' => 'maria@exemplo.com']);
        $this->postJson('/api/ferrakora/cadastro', ['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'outrasenha1'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_senha_curta_e_recusada(): void
    {
        $this->postJson('/api/ferrakora/cadastro', ['name' => 'Outro', 'email' => 'outro@exemplo.com', 'password' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
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

    public function test_esqueci_a_senha_manda_o_link_do_ferrakora_e_nova_senha_entra(): void
    {
        Mail::fake();
        $velho = $this->cadastrar('maria@exemplo.com');
        User::factory()->create(['email' => 'so-kazakora@exemplo.com']);

        $this->postJson('/api/ferrakora/esqueci-senha', ['email' => 'maria@exemplo.com'])->assertOk();
        $this->postJson('/api/ferrakora/esqueci-senha', ['email' => 'ninguem@exemplo.com'])->assertOk();
        $this->postJson('/api/ferrakora/esqueci-senha', ['email' => 'so-kazakora@exemplo.com'])->assertOk();

        $link = null;
        Mail::assertSent(FerraKoraNovaSenha::class, function (FerraKoraNovaSenha $mail) use (&$link) {
            $link = $mail->link;

            return $mail->hasTo('maria@exemplo.com');
        });
        Mail::assertSent(FerraKoraNovaSenha::class, 1);
        $this->assertStringStartsWith('https://ferrakora.devlira.com.br/nova-senha?', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->postJson('/api/ferrakora/nova-senha', ['email' => 'maria@exemplo.com', 'token' => 'errado', 'password' => 'novaSenha123'])->assertStatus(422);

        $novo = $this->postJson('/api/ferrakora/nova-senha', ['email' => 'maria@exemplo.com', 'token' => $query['token'], 'password' => 'novaSenha123'])
            ->assertOk()->json('token');
        $this->withToken($novo)->getJson('/api/ferrakora/eu')->assertOk();
        $this->withToken($velho)->getJson('/api/ferrakora/eu')->assertUnauthorized();
        $this->postJson('/api/ferrakora/login', ['email' => 'maria@exemplo.com', 'password' => 'novaSenha123'])->assertOk();

        // O link vale uma vez só.
        $this->postJson('/api/ferrakora/nova-senha', ['email' => 'maria@exemplo.com', 'token' => $query['token'], 'password' => 'outra12345'])->assertStatus(422);
    }

    public function test_link_vencido_nao_troca_a_senha(): void
    {
        Mail::fake();
        $this->cadastrar('maria@exemplo.com');
        $this->postJson('/api/ferrakora/esqueci-senha', ['email' => 'maria@exemplo.com'])->assertOk();
        $link = null;
        Mail::assertSent(FerraKoraNovaSenha::class, function ($mail) use (&$link) {
            $link = $mail->link;

            return true;
        });
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->travel(61)->minutes();
        $this->postJson('/api/ferrakora/nova-senha', ['email' => 'maria@exemplo.com', 'token' => $query['token'], 'password' => 'novaSenha123'])->assertStatus(422);
    }

    public function test_conta_criada_pelo_checkout_pede_esqueci_minha_senha(): void
    {
        FerraKoraCliente::doCheckout(['name' => 'Maria', 'email' => 'Maria@Exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '11988887777']);

        $this->postJson('/api/ferrakora/login', ['email' => 'maria@exemplo.com', 'password' => 'qualquer1'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Esqueci minha senha'));
        $this->postJson('/api/ferrakora/cadastro', ['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'segredo123'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }
}

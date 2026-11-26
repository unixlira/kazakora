<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Mail\CodigoLoginMail;
use App\Modules\Auth\Support\CodigoLoginAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Login em 2 fatores do admin por código no e-mail (auditoria 2026-11-25).
 */
class AdminDoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    private ?string $codigoEnviado = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.admin_2fa.ativo' => true]);
        Mail::fake();
    }

    private function entrarComSenha(User $user, string $host = 'https://painel.kazakora.com', array $extra = [])
    {
        return $this->post($host.'/entrar', ['email' => $user->email, 'password' => 'password', ...$extra]);
    }

    private function ultimoCodigo(): string
    {
        $codigo = null;
        Mail::assertSent(CodigoLoginMail::class, function (CodigoLoginMail $mail) use (&$codigo) {
            $codigo = $mail->codigo;

            return true;
        });

        return $codigo;
    }

    private function codigoErrado(string $certo): string
    {
        return $certo === '000000' ? '111111' : '000000';
    }

    public function test_admin_com_senha_certa_recebe_codigo_e_nao_entra_ainda(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->entrarComSenha($admin)->assertRedirect(route('entrar.codigo'));

        $this->assertGuest();
        Mail::assertSent(CodigoLoginMail::class, fn (CodigoLoginMail $mail) => $mail->hasTo($admin->email)
            && preg_match('/^\d{6}$/', $mail->codigo) === 1);

        $this->get('https://painel.kazakora.com/entrar/codigo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/CodigoLogin', false)->where('validadeMinutos', 10));
    }

    public function test_gerente_e_assinante_do_painel_tambem_pedem_codigo(): void
    {
        foreach ([User::ROLE_MANAGER, User::ROLE_SUBSCRIBER] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->entrarComSenha($user)->assertRedirect(route('entrar.codigo'));
            $this->assertGuest();
            $this->flushSession();
        }
    }

    public function test_senha_errada_nao_manda_codigo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->post('https://painel.kazakora.com/entrar', ['email' => $admin->email, 'password' => 'errada'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
        $this->assertGuest();
    }

    public function test_codigo_certo_completa_o_login(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->entrarComSenha($admin);

        $this->post('https://painel.kazakora.com/entrar/codigo', ['codigo' => $this->ultimoCodigo()])
            ->assertRedirect('https://painel.kazakora.com/admin');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_codigo_errado_nao_entra_e_bloqueia_depois_de_5(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->entrarComSenha($admin);
        $certo = $this->ultimoCodigo();

        for ($i = 1; $i <= 4; $i++) {
            $this->post('https://painel.kazakora.com/entrar/codigo', ['codigo' => $this->codigoErrado($certo)])
                ->assertSessionHasErrors('codigo');
            $this->assertGuest();
        }

        // 5º erro: o código morre e volta para a senha.
        $this->post('https://painel.kazakora.com/entrar/codigo', ['codigo' => $this->codigoErrado($certo)])
            ->assertRedirect(route('entrar'));

        // Nem o código certo vale mais.
        $this->post('https://painel.kazakora.com/entrar/codigo', ['codigo' => $certo])
            ->assertRedirect(route('entrar'));
        $this->assertGuest();
    }

    public function test_codigo_expirado_nao_entra(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->entrarComSenha($admin);
        $codigo = $this->ultimoCodigo();

        $this->travel(11)->minutes();

        $this->post('https://painel.kazakora.com/entrar/codigo', ['codigo' => $codigo])
            ->assertRedirect(route('entrar'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_codigo_sem_passar_pela_senha_nao_entra(): void
    {
        $this->post('https://painel.kazakora.com/entrar/codigo', ['codigo' => '123456'])
            ->assertRedirect(route('entrar'));
        $this->get('https://painel.kazakora.com/entrar/codigo')->assertRedirect(route('entrar'));
        $this->assertGuest();
    }

    public function test_lembrar_dispositivo_pula_o_codigo_por_30_dias(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->entrarComSenha($admin);

        $resposta = $this->post('https://painel.kazakora.com/entrar/codigo', ['codigo' => $this->ultimoCodigo(), 'lembrar' => true]);
        $cookie = $resposta->getCookie(CodigoLoginAdmin::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());

        $this->post('https://painel.kazakora.com/sair');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        Mail::fake();

        // Mesmo dispositivo: entra só com a senha.
        $this->withCookie(CodigoLoginAdmin::COOKIE, $cookie->getValue());
        $this->entrarComSenha($admin)->assertRedirect('https://painel.kazakora.com/admin');
        $this->assertAuthenticatedAs($admin);
        Mail::assertNothingSent();

        // Passados os 30 dias, volta a pedir o código.
        $this->post('https://painel.kazakora.com/sair');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->travel(31)->days();
        $this->entrarComSenha($admin)->assertRedirect(route('entrar.codigo'));
        $this->assertGuest();
    }

    public function test_cookie_de_outro_usuario_ou_depois_de_trocar_a_senha_nao_vale(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $outro = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $valor = app(CodigoLoginAdmin::class)->cookieLembrar($admin)->getValue();

        $this->withCookie(CodigoLoginAdmin::COOKIE, $valor);
        $this->entrarComSenha($outro)->assertRedirect(route('entrar.codigo'));
        $this->flushSession();

        $admin->update(['password' => 'password']); // novo hash
        $this->entrarComSenha($admin)->assertRedirect(route('entrar.codigo'));
        $this->assertGuest();
    }

    public function test_cliente_entra_sem_codigo(): void
    {
        $cliente = User::factory()->create();

        $this->post('https://kazakora.com/entrar', ['email' => $cliente->email, 'password' => 'password'])
            ->assertRedirect('https://kazakora.com');

        $this->assertAuthenticatedAs($cliente);
        Mail::assertNotSent(CodigoLoginMail::class);
    }

    public function test_admin_pela_loja_vai_para_o_painel_depois_do_codigo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->entrarComSenha($admin, 'https://kazakora.com')->assertRedirect(route('entrar.codigo'));

        $destino = $this->post('https://kazakora.com/entrar/codigo', ['codigo' => $this->ultimoCodigo()])->headers->get('Location');
        $this->assertStringStartsWith('https://painel.kazakora.com/entrar/painel/'.$admin->id.'?', $destino);
    }

    public function test_reenviar_troca_o_codigo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->entrarComSenha($admin);

        $this->post('https://painel.kazakora.com/entrar/codigo/reenviar')->assertRedirect(route('entrar.codigo'));

        Mail::assertSent(CodigoLoginMail::class, 2);
    }

    public function test_chave_desligada_admin_entra_so_com_senha(): void
    {
        config(['auth.admin_2fa.ativo' => false]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->entrarComSenha($admin)->assertRedirect('https://painel.kazakora.com/admin');
        $this->assertAuthenticatedAs($admin);
        Mail::assertNothingSent();
    }
}

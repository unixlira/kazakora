<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Notifications\AlertaAdminNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Comando alertas:conteudo-fk: a rotina de conteúdo da FerraKora avisa no painel (2026-10-29). */
class AlertaConteudoFkTest extends TestCase
{
    use RefreshDatabase;

    public function test_tipo_conteudo_fk_existe(): void
    {
        $this->assertSame('Falha no conteúdo automático da FerraKora', AlertasAdmin::TIPOS[AlertasAdmin::CONTEUDO_FK]['nome']);
    }

    public function test_abre_alerta_e_avisa_no_sino(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->artisan('alertas:conteudo-fk', ['chave' => 'instagram', 'titulo' => 'Post do Instagram falhou', 'mensagem' => 'Token expirado'])
            ->assertSuccessful();

        $this->assertDatabaseHas('admin_alertas', [
            'tipo' => 'conteudo_fk', 'chave' => 'instagram', 'titulo' => 'Post do Instagram falhou',
            'mensagem' => 'Token expirado', 'resolvido_em' => null,
        ]);
        Notification::assertSentTo($admin, AlertaAdminNotification::class);
    }

    public function test_mesma_chave_soma_ocorrencia(): void
    {
        $this->artisan('alertas:conteudo-fk', ['chave' => 'tiktok', 'titulo' => 'TikTok falhou', 'mensagem' => 'A'])->assertSuccessful();
        $this->artisan('alertas:conteudo-fk', ['chave' => 'tiktok', 'titulo' => 'TikTok falhou', 'mensagem' => 'B'])->assertSuccessful();

        $alerta = AlertaAdmin::query()->where('tipo', 'conteudo_fk')->sole();
        $this->assertSame(2, $alerta->ocorrencias);
        $this->assertSame('B', $alerta->mensagem);
    }

    public function test_resolver_fecha_so_a_chave_informada(): void
    {
        $this->artisan('alertas:conteudo-fk', ['chave' => 'instagram', 'titulo' => 'IG', 'mensagem' => 'x'])->assertSuccessful();
        $this->artisan('alertas:conteudo-fk', ['chave' => 'facebook', 'titulo' => 'FB', 'mensagem' => 'y'])->assertSuccessful();

        $this->artisan('alertas:conteudo-fk', ['chave' => 'instagram', '--resolver' => true])->assertSuccessful();

        $this->assertNotNull(AlertaAdmin::query()->where('chave', 'instagram')->sole()->resolvido_em);
        $this->assertNull(AlertaAdmin::query()->where('chave', 'facebook')->sole()->resolvido_em);
    }

    public function test_sem_titulo_falha_e_nao_abre(): void
    {
        $this->artisan('alertas:conteudo-fk', ['chave' => 'instagram'])->assertFailed();

        $this->assertSame(0, AlertaAdmin::query()->count());
    }

    public function test_tipo_desligado_nao_abre(): void
    {
        app(AlertasAdmin::class)->definirAtivo(AlertasAdmin::CONTEUDO_FK, false);

        $this->artisan('alertas:conteudo-fk', ['chave' => 'instagram', 'titulo' => 'IG', 'mensagem' => 'x'])->assertSuccessful();

        $this->assertSame(0, AlertaAdmin::query()->count());
    }
}

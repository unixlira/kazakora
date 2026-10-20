<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Financeiro\Support\AlertaColetaFinanceira;
use App\Modules\Marketplace\Support\RelatoriosAmazon;
use App\Modules\Marketplace\Support\RelatoriosTikTok;
use App\Notifications\AlertaAdminNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Alertas do painel do admin; o primeiro é a falha da coleta financeira (2026-10-16). */
class AlertasAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // WhatsApp desligado: a faixa do painel tem que aparecer mesmo assim.
        config(['services.whatsapp.alerta_coleta' => ['ativo' => false, 'para' => '']]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_falha_da_coleta_abre_alerta_no_painel_mesmo_com_whatsapp_desligado(): void
    {
        $status = app(AlertaColetaFinanceira::class)->avisar('Amazon', 'Seller Central pediu login de novo.');

        $this->assertSame('desligado', $status);
        $this->assertDatabaseHas('admin_alertas', [
            'tipo' => AlertasAdmin::COLETA_FINANCEIRA, 'chave' => 'amazon',
            'mensagem' => 'Seller Central pediu login de novo.', 'ocorrencias' => 1, 'resolvido_em' => null,
        ]);
    }

    public function test_mesma_falha_aberta_soma_ocorrencia_e_atualiza_motivo(): void
    {
        $alerta = app(AlertaColetaFinanceira::class);
        $alerta->avisar('TikTok', 'Sessão caiu');
        $alerta->avisar('TikTok', 'Tela mudou');
        $alerta->avisar('Amazon', 'Download falhou');

        $this->assertSame(2, AlertaAdmin::query()->abertos()->count());
        $tiktok = AlertaAdmin::query()->where('chave', 'tiktok')->sole();
        $this->assertSame(2, $tiktok->ocorrencias);
        $this->assertSame('Tela mudou', $tiktok->mensagem);
        $this->assertSame('/admin/arquivos-tiktok', $tiktok->link);
    }

    public function test_rota_do_agente_abre_alerta(): void
    {
        config(['services.tiktok_reports.token' => 'segredo-de-teste']);

        $this->withToken('segredo-de-teste')
            ->postJson('/api/financeiro/coletas/falha', ['canal' => 'tiktok', 'motivo' => 'Chrome da Cora Box não abriu'])
            ->assertOk();

        $this->assertDatabaseHas('admin_alertas', ['chave' => 'tiktok', 'mensagem' => 'Chrome da Cora Box não abriu']);
    }

    public function test_coleta_que_volta_resolve_so_o_alerta_do_proprio_canal(): void
    {
        $alerta = app(AlertaColetaFinanceira::class);
        $alerta->avisar('TikTok', 'Sessão caiu');
        $alerta->avisar('Amazon', 'Sessão caiu');

        app(RelatoriosTikTok::class)->avisar(true, 'arquivo.xlsx importado');

        $this->assertNotNull(AlertaAdmin::query()->where('chave', 'tiktok')->sole()->resolvido_em);
        $this->assertNull(AlertaAdmin::query()->where('chave', 'amazon')->sole()->resolvido_em);

        app(RelatoriosAmazon::class)->avisar(true, 'ok');
        $this->assertSame(0, AlertaAdmin::query()->abertos()->count());
    }

    public function test_falha_depois_de_resolvido_abre_alerta_novo(): void
    {
        $alertas = app(AlertasAdmin::class);
        $alertas->disparar(AlertasAdmin::COLETA_FINANCEIRA, 'tiktok', 'Falha', 'a');
        $alertas->resolver(AlertasAdmin::COLETA_FINANCEIRA, 'tiktok');
        $alertas->disparar(AlertasAdmin::COLETA_FINANCEIRA, 'tiktok', 'Falha', 'b');

        $this->assertSame(2, AlertaAdmin::query()->count());
        $this->assertSame(1, AlertaAdmin::query()->abertos()->count());
    }

    public function test_alerta_vai_para_o_sino_do_admin_e_nao_para_faixa_no_meio_da_tela(): void
    {
        $admin = $this->admin();
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $alerta = app(AlertaColetaFinanceira::class);
        $alerta->avisar('Amazon', 'Seller Central pediu login de novo.');
        $alerta->avisar('Amazon', 'Seller Central pediu login de novo.');

        // Uma notificação só por alerta aberto, mesmo com a falha repetindo.
        $this->assertSame(1, $admin->notifications()->where('type', AlertaAdminNotification::class)->count());
        $this->assertSame(0, $gerente->notifications()->count());

        $this->actingAs($admin)->get('/admin/alertas')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Alertas/Index', false)
                ->missing('alertasAdmin')
                ->missing('sidebarBadges.alertasAbertos')
                ->where('notifications.unreadCount', 1)
                ->where('notifications.items.0.message', 'Falha na coleta financeira Amazon (Cora Box)')
                ->where('notifications.items.0.body', 'Seller Central pediu login de novo.')
                ->where('notifications.items.0.nivel', 'erro')
                ->has('abertos', 1));

        // Na loja o sino não mostra alerta interno.
        $this->actingAs($admin)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('notifications.unreadCount', 0));

        $this->actingAs($gerente)->get('/admin/alertas')->assertForbidden();
        $this->actingAs($admin)->getJson('/admin/alertas/abertos')->assertNotFound();
    }

    public function test_alerta_resolvido_sai_do_sino(): void
    {
        $admin = $this->admin();
        app(AlertaColetaFinanceira::class)->avisar('TikTok', 'Sessão caiu');
        app(AlertaColetaFinanceira::class)->avisar('Amazon', 'Download falhou');
        $alerta = AlertaAdmin::query()->where('chave', 'tiktok')->sole();

        $this->actingAs($admin)->post("/admin/alertas/{$alerta->id}/resolver")->assertRedirect();

        $this->assertSame($admin->name, $alerta->fresh()->resolvido_por);
        $naoLidas = $admin->unreadNotifications()->where('type', AlertaAdminNotification::class)->get();
        $this->assertCount(1, $naoLidas);
        $this->assertSame('Falha na coleta financeira Amazon (Cora Box)', $naoLidas->first()->data['message']);

        // O sistema resolvendo sozinho (relatório entrou) também tira do sino.
        app(RelatoriosAmazon::class)->avisar(true, 'ok');
        $this->assertSame(0, $admin->unreadNotifications()->where('type', AlertaAdminNotification::class)->count());
    }

    public function test_tipo_desligado_nao_abre_alerta_e_fecha_os_abertos(): void
    {
        app(AlertaColetaFinanceira::class)->avisar('TikTok', 'Sessão caiu');

        $this->actingAs($this->admin())->post('/admin/alertas/tipos/coleta_financeira', ['ativo' => false])->assertRedirect();

        $this->assertSame(0, AlertaAdmin::query()->abertos()->count());
        app(AlertaColetaFinanceira::class)->avisar('Amazon', 'Download falhou');
        $this->assertSame(0, AlertaAdmin::query()->abertos()->count());

        $this->actingAs($this->admin())->post('/admin/alertas/tipos/nao_existe', ['ativo' => true])->assertNotFound();
    }
}

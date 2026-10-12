<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Origem das visitas (pedido 2026-10-12): o TrackSiteVisit grava a origem
 * classificada e o identificador de clique; a tela Visitas ao site mostra
 * por onde o visitante entrou e o olho mostra Referer, UTM e clique.
 */
class VisitasOrigemTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://kazakora.com']);
    }

    private function visita(string $ip, string $quando, ?string $origem, array $extra = []): void
    {
        DB::table('site_visits')->insert([
            'visitor_id' => 'v-'.$ip, 'path' => '/', 'ip' => $ip, 'consentiu' => true,
            'created_at' => $quando, 'dispositivo' => 'celular', 'origem' => $origem, ...$extra,
        ]);
    }

    public function test_grava_origem_e_parametro_de_clique_sem_o_valor_quando_nao_deu_ok(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withHeader('Referer', 'https://l.instagram.com/?u=x')
            ->get('https://kazakora.com/politica-de-entrega?fbclid=IwAR123');

        $visita = SiteVisit::sole();
        $this->assertSame('instagram', $visita->origem);
        $this->assertSame('fbclid', $visita->click_id_param);
        $this->assertNull($visita->click_id);
        $this->assertSame('https://l.instagram.com/', $visita->referer);
    }

    public function test_com_ok_grava_o_valor_do_clique_e_utm_vale_mais_que_o_referer(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->withCookie(Cookies::COOKIE_VISITANTE, 'visitante-1')
            ->withHeader('User-Agent', self::NAVEGADOR)
            ->withHeader('Referer', 'https://www.google.com.br/')
            ->get('https://kazakora.com/politica-de-entrega?utm_source=google&utm_medium=cpc&gclid=Cj0KCQ');

        $visita = SiteVisit::sole();
        $this->assertSame('google_ads', $visita->origem);
        $this->assertSame('gclid', $visita->click_id_param);
        $this->assertSame('Cj0KCQ', $visita->click_id);
    }

    public function test_sem_referer_e_direto_e_do_proprio_site_e_interno(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/politica-de-entrega');
        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withHeader('Referer', 'https://kazakora.com/')
            ->get('https://kazakora.com/politica-de-entrega');

        $this->assertSame(['direto', 'interno'], SiteVisit::query()->orderBy('id')->pluck('origem')->all());
    }

    public function test_lista_mostra_a_origem_de_entrada_sem_dado_e_filtra(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Entrou pelo Instagram, navegou (interno) e depois voltou pelo WhatsApp.
        $this->visita('1.1.1.1', '2026-10-12 10:00:00', 'instagram', ['referer' => 'https://l.instagram.com/']);
        $this->visita('1.1.1.1', '2026-10-12 10:01:00', 'interno', ['referer' => 'https://kazakora.com/']);
        $this->visita('1.1.1.1', '2026-10-12 12:00:00', 'whatsapp', ['referer' => 'https://wa.me/']);
        // Visita antiga, sem origem gravada.
        $this->visita('2.2.2.2', '2026-10-12 11:00:00', null);
        // Outro site com o domínio no rótulo.
        $this->visita('3.3.3.3', '2026-10-12 09:00:00', 'outro', ['referer' => 'https://blog.exemplo.com/post']);

        $this->actingAs($admin)->get('/admin/visitas-site')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('visitas.data', 3)
                ->where('visitas.data.0.ip', '2.2.2.2')
                ->where('visitas.data.0.origem.chave', 'sem_dado')
                ->where('visitas.data.0.origem.rotulo', 'Sem dado')
                ->where('visitas.data.1.ip', '1.1.1.1')
                ->where('visitas.data.1.origem.rotulo', 'Instagram')
                ->where('visitas.data.1.origem.outras', ['WhatsApp'])
                ->where('visitas.data.2.origem.rotulo', 'Outro site (blog.exemplo.com)')
                ->has('origens'));

        $this->actingAs($admin)->get('/admin/visitas-site?origem=whatsapp')
            ->assertInertia(fn ($page) => $page
                ->has('visitas.data', 1)
                ->where('visitas.data.0.ip', '1.1.1.1')
                ->where('visitas.data.0.paginas', 3));

        $this->actingAs($admin)->get('/admin/visitas-site?origem=sem_dado')
            ->assertInertia(fn ($page) => $page->has('visitas.data', 1)->where('visitas.data.0.ip', '2.2.2.2'));

        $this->actingAs($admin)->get('/admin/visitas-site?origem=nao-existe')->assertSessionHasErrors('origem');
    }

    public function test_olho_mostra_referer_utm_e_clique_de_cada_entrada(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->visita('1.1.1.1', '2026-10-12 10:00:00', 'meta', [
            'utm_source' => null, 'click_id_param' => 'fbclid', 'click_id' => 'IwAR1', 'path' => '/produto/caneca',
        ]);
        // Recarregou a mesma página: mesma origem seguida não repete.
        $this->visita('1.1.1.1', '2026-10-12 10:00:30', 'meta', ['click_id_param' => 'fbclid', 'click_id' => 'IwAR1']);
        $this->visita('1.1.1.1', '2026-10-12 10:02:00', 'interno', ['referer' => 'https://kazakora.com/']);
        $this->visita('1.1.1.1', '2026-10-12 11:00:00', 'campanha', [
            'referer' => 'https://parceiro.com.br/', 'utm_source' => 'parceiro', 'utm_medium' => 'post', 'utm_campaign' => 'outubro',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/visitas-site/visitante?de=2026-10-12&ate=2026-10-12&ip=1.1.1.1')
            ->assertOk()
            ->assertJsonCount(2, 'entradas')
            ->assertJsonPath('entradas.0.rotulo', 'Instagram/Facebook')
            ->assertJsonPath('entradas.0.click_id_param', 'fbclid')
            ->assertJsonPath('entradas.0.click_id', 'IwAR1')
            ->assertJsonPath('entradas.0.path', '/produto/caneca')
            ->assertJsonPath('entradas.1.rotulo', 'Campanha (parceiro)')
            ->assertJsonPath('entradas.1.referer', 'https://parceiro.com.br/')
            ->assertJsonPath('entradas.1.utm_campaign', 'outubro')
            ->assertJsonPath('sem_dado', false)
            ->assertJsonPath('visitas.2.origem.chave', 'interno');
    }

    public function test_olho_de_visita_antiga_avisa_sem_dado(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->visita('2.2.2.2', '2026-10-12 11:00:00', null);

        $this->actingAs($admin)
            ->getJson('/admin/visitas-site/visitante?de=2026-10-12&ate=2026-10-12&ip=2.2.2.2')
            ->assertOk()
            ->assertJsonCount(0, 'entradas')
            ->assertJsonPath('sem_dado', true)
            ->assertJsonPath('visitas.0.origem.rotulo', 'Sem dado');
    }
}

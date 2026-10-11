<?php

namespace Tests\Feature\Loja;

use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Modules\Analytics\Models\SiteVisit;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Cookies e LGPD (pedido 2026-10-10, docs/privacidade-e-cookies.md). */
class CookiesTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    public function test_sem_ok_mostra_aviso_e_nao_cria_cookie_de_estatistica(): void
    {
        $resposta = $this->withHeader('User-Agent', self::NAVEGADOR)->get('/politica-de-entrega?utm_source=instagram&utm_campaign=black');

        $resposta->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cookies.aceito', false))
            ->assertCookieMissing(Cookies::COOKIE_VISITANTE);

        $visita = SiteVisit::sole();
        $this->assertFalse($visita->consentiu);
        $this->assertStringStartsWith('s-', $visita->visitor_id);
        $this->assertNull($visita->user_agent);
        $this->assertNotNull($visita->ip);
        $this->assertSame('instagram', $visita->utm_source);
        $this->assertSame('black', $visita->utm_campaign);
        $this->assertSame('celular', $visita->dispositivo);
    }

    public function test_ok_grava_prova_e_cria_cookies(): void
    {
        $this->from('/politica-de-entrega')->post('/cookies/aceitar')
            ->assertRedirect('/politica-de-entrega')
            ->assertCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->assertCookie(Cookies::COOKIE_VISITANTE);

        $prova = ConsentimentoCookie::sole();
        $this->assertSame(Cookies::VERSAO, $prova->versao);
        $this->assertNotNull($prova->ip);
    }

    public function test_com_ok_aviso_some_e_visita_usa_o_cookie(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->withCookie(Cookies::COOKIE_VISITANTE, 'visitante-123')
            ->withHeader('User-Agent', self::NAVEGADOR)
            ->get('/politica-de-entrega')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cookies.aceito', true));

        $visita = SiteVisit::sole();
        $this->assertTrue($visita->consentiu);
        $this->assertSame('visitante-123', $visita->visitor_id);
        $this->assertNotNull($visita->user_agent);
    }

    public function test_ok_de_versao_antiga_mostra_o_aviso_de_novo(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, '2020-01-01')->get('/politica-de-entrega')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cookies.aceito', false));
    }

    public function test_origem_vai_sem_o_restante_do_endereco(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)->withHeader('Referer', 'https://www.google.com/search?q=maria@exemplo.com')->get('/politica-de-entrega');

        $this->assertSame('https://www.google.com/search', SiteVisit::sole()->referer);
    }

    public function test_politica_de_cookies_abre(): void
    {
        $this->get('/politica-de-cookies')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Legal/Cookies', false)
            ->where('versao', Cookies::VERSAO));
    }

    public function test_limpeza_anonimiza_visitas_com_mais_de_6_meses(): void
    {
        $antiga = SiteVisit::create(['visitor_id' => 'abc', 'path' => '/', 'ip' => '1.2.3.4', 'user_agent' => 'X']);
        $antiga->forceFill(['created_at' => now()->subMonths(7)])->save();
        $recente = SiteVisit::create(['visitor_id' => 'def', 'path' => '/', 'ip' => '5.6.7.8']);

        $this->artisan('privacidade:limpar')->assertSuccessful();

        $this->assertNull($antiga->fresh()->ip);
        $this->assertSame('anonimo', $antiga->fresh()->visitor_id);
        $this->assertSame('5.6.7.8', $recente->fresh()->ip);
    }
}

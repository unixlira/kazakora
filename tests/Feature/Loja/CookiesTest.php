<?php

namespace Tests\Feature\Loja;

use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Modules\Analytics\Models\SiteVisit;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Cookies e LGPD (pedido 2026-10-10, docs/privacidade-e-cookies.md). */
class CookiesTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    public function test_sem_ok_cria_so_o_cookie_de_visitante_no_primeiro_acesso(): void
    {
        $resposta = $this->withHeader('User-Agent', self::NAVEGADOR)->get('/politica-de-entrega?utm_source=instagram&utm_campaign=black');

        // Desde 2026-10-25 o kazakora_visitor nasce no primeiro acesso (histórico de navegação).
        $resposta->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cookies.aceito', false))
            ->assertCookie(Cookies::COOKIE_VISITANTE)
            ->assertCookieMissing(Cookies::COOKIE_OK);

        $visita = SiteVisit::sole();
        $this->assertFalse($visita->consentiu);
        $this->assertTrue(Str::isUuid($visita->visitor_id));
        $this->assertNull($visita->cookies);
        $this->assertNull($visita->user_agent);
        $this->assertNotNull($visita->ip);
        $this->assertSame('instagram', $visita->utm_source);
        $this->assertSame('black', $visita->utm_campaign);
        $this->assertSame('celular', $visita->dispositivo);
    }

    public function test_pixel_da_meta_vai_nas_props_para_o_front_so_carregar_apos_o_ok(): void
    {
        config(['services.meta_ads.pixel_id' => '2186107885537817']);

        $this->get('/politica-de-entrega')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cookies.aceito', false)
            ->where('cookies.pixelMeta', '2186107885537817'));
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
        $this->assertSame('kazakora', $prova->origem);
    }

    public function test_ok_grava_a_origem_enviada_e_ignora_valor_desconhecido(): void
    {
        $this->post('/cookies/aceitar', ['origem' => 'beautykora']);
        $this->assertSame('beautykora', ConsentimentoCookie::sole()->origem);

        ConsentimentoCookie::query()->delete();
        $this->post('/cookies/aceitar', ['origem' => 'outro-site']);
        $this->assertSame('kazakora', ConsentimentoCookie::sole()->origem);
    }

    public function test_ok_conta_a_pagina_onde_o_cliente_aceitou(): void
    {
        config(['app.url' => 'https://kazakora.com']);

        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->from('https://kazakora.com/produtos/fone?utm_source=instagram')
            ->post('https://kazakora.com/cookies/aceitar');

        $visita = SiteVisit::sole();
        $this->assertTrue($visita->consentiu);
        $this->assertSame('/produtos/fone', $visita->path);
        $this->assertSame('instagram', $visita->utm_source);
        $this->assertSame(ConsentimentoCookie::sole()->visitante_id, $visita->visitor_id);
        $this->assertCount(1, SiteVisit::visitantesPorDia(SiteVisit::query()));
    }

    public function test_ok_vindo_de_outro_dominio_nao_conta_visita(): void
    {
        config(['app.url' => 'https://kazakora.com']);

        $this->from('https://kazakora.devlira.com.br/')->post('https://kazakora.com/cookies/aceitar');

        $this->assertSame(0, SiteVisit::count());
    }

    public function test_sem_ok_conta_um_visitante_por_ip(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)->get('/politica-de-entrega');
        $this->withHeader('User-Agent', self::NAVEGADOR)->get('/politica-de-entrega');

        $this->assertSame(2, SiteVisit::count());
        $visitantes = SiteVisit::visitantesPorDia(SiteVisit::query());
        $this->assertCount(1, $visitantes);
        $this->assertSame(2, $visitantes->first()->paginas);
    }

    public function test_sem_ok_todas_as_paginas_ficam_no_mesmo_visitante(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)->get('/politica-de-entrega')->assertCookie(Cookies::COOKIE_VISITANTE);
        $codigo = SiteVisit::sole()->visitor_id;

        // Sem o cookie de volta (navegador que recusa cookie) vale o código da sessão; com ele, o cookie.
        $this->withHeader('User-Agent', self::NAVEGADOR)->get('/termos-de-uso');
        $this->withCookie(Cookies::COOKIE_VISITANTE, $codigo)->withHeader('User-Agent', self::NAVEGADOR)->get('/politica-de-cookies');

        $visitas = SiteVisit::query()->orderBy('id')->get();
        $this->assertSame(['/politica-de-entrega', '/termos-de-uso', '/politica-de-cookies'], $visitas->pluck('path')->all());
        $this->assertSame([$codigo], $visitas->pluck('visitor_id')->unique()->values()->all());
    }

    public function test_cookie_de_visitante_que_ja_existe_nao_e_trocado(): void
    {
        $this->withCookie(Cookies::COOKIE_VISITANTE, 'b6a1c1d2-0000-4000-8000-000000000009')
            ->withHeader('User-Agent', self::NAVEGADOR)
            ->get('/politica-de-entrega')
            ->assertCookieMissing(Cookies::COOKIE_VISITANTE);

        $this->assertSame('b6a1c1d2-0000-4000-8000-000000000009', SiteVisit::sole()->visitor_id);
    }

    public function test_robo_nao_ganha_cookie_de_visitante(): void
    {
        $this->withHeader('User-Agent', 'curl/8.0')->get('/politica-de-entrega')->assertCookieMissing(Cookies::COOKIE_VISITANTE);

        $this->assertSame(0, SiteVisit::count());
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

    public function test_ok_do_aviso_de_2026_10_18_continua_valendo(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, '2026-10-18')->get('/politica-de-entrega')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cookies.aceito', true));
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

    public function test_com_ok_grava_so_os_cookies_da_lista_permitida(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->withCookie(Cookies::COOKIE_VISITANTE, 'visitante-123')
            // Cookies de terceiros chegam em texto puro no cabeçalho Cookie.
            ->withHeader('Cookie', '_fbp=fb.1.1700000000.111; _ga_ABC123=GS1.1.99; kazakora-session=sessao-secreta; XSRF-TOKEN=token-secreto; outro=qualquer')
            ->withHeader('User-Agent', self::NAVEGADOR)
            ->get('/politica-de-entrega')->assertOk();

        $cookies = SiteVisit::sole()->cookies;

        $this->assertSame([
            '_fbp' => 'fb.1.1700000000.111',
            '_ga_ABC123' => 'GS1.1.99',
            Cookies::COOKIE_OK => Cookies::VERSAO,
            Cookies::COOKIE_VISITANTE => 'visitante-123',
        ], $cookies);
        $this->assertStringNotContainsString('secret', json_encode($cookies));
    }

    public function test_sem_ok_nao_grava_nenhum_cookie(): void
    {
        $this->withHeader('Cookie', '_fbp=fb.1.1700000000.111')
            ->withHeader('User-Agent', self::NAVEGADOR)
            ->get('/politica-de-entrega')->assertOk();

        $this->assertNull(SiteVisit::sole()->cookies);
    }

    public function test_valor_de_cookie_muito_longo_e_cortado(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->withHeader('Cookie', '_fbc='.str_repeat('a', 500))
            ->withHeader('User-Agent', self::NAVEGADOR)
            ->get('/politica-de-entrega');

        $this->assertSame(Cookies::LIMITE_VALOR_COOKIE, strlen(SiteVisit::sole()->cookies['_fbc']));
    }

    public function test_pagina_do_ok_grava_os_cookies_recem_criados(): void
    {
        config(['app.url' => 'https://kazakora.com']);

        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->from('https://kazakora.com/produtos/fone')
            ->post('https://kazakora.com/cookies/aceitar');

        $visita = SiteVisit::sole();
        $this->assertSame($visita->visitor_id, $visita->cookies[Cookies::COOKIE_VISITANTE]);
        $this->assertSame(Cookies::VERSAO, $visita->cookies[Cookies::COOKIE_OK]);
    }

    public function test_limpeza_apaga_cookies_de_visita_com_mais_de_6_meses(): void
    {
        $antiga = SiteVisit::create(['visitor_id' => 'abc', 'path' => '/', 'cookies' => ['_fbp' => 'x']]);
        $antiga->forceFill(['created_at' => now()->subMonths(7)])->save();

        $this->artisan('privacidade:limpar')->assertSuccessful();

        $this->assertNull($antiga->fresh()->cookies);
    }

    public function test_ok_na_pagina_ja_gravada_completa_a_visita_sem_duplicar(): void
    {
        config(['app.url' => 'https://kazakora.com']);
        $this->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/politica-de-entrega?utm_source=meta&fbclid=IwAR0abcdefghij');
        $visitante = SiteVisit::sole()->visitor_id;

        // A faixa aceita sozinha em 3 s (2026-10-25): a página não vira uma segunda visita.
        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withCookie(Cookies::COOKIE_VISITANTE, $visitante)
            ->from('https://kazakora.com/politica-de-entrega?utm_source=meta&fbclid=IwAR0abcdefghij')
            ->post('https://kazakora.com/cookies/aceitar');

        $visita = SiteVisit::sole();
        $this->assertTrue($visita->consentiu);
        $this->assertSame('meta', $visita->utm_source);
        $this->assertSame('IwAR0abcdefghij', $visita->click_id);
        $this->assertSame(self::NAVEGADOR, $visita->user_agent);
    }

    public function test_robo_da_rede_da_meta_nao_conta_visita(): void
    {
        config(['app.url' => 'https://kazakora.com']);

        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withServerVariables(['REMOTE_ADDR' => '2a03:2880:f810:31::1'])
            ->get('https://kazakora.com/politica-de-entrega');
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; meta-externalads/1.1)')->get('https://kazakora.com/politica-de-entrega');

        $this->assertSame(0, SiteVisit::count());
    }

    public function test_clique_do_anuncio_fica_na_sessao_e_vira_fbc_no_aceite(): void
    {
        config(['app.url' => 'https://kazakora.com']);

        $this->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/politica-de-entrega?fbclid=IwAR0abcdefghij')
            ->assertSessionHas('meta_fbclid.valor', 'IwAR0abcdefghij');

        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withSession(['meta_fbclid' => ['valor' => 'IwAR0abcdefghij', 'em' => 1761440000000]])
            ->from('https://seguro.kazakora.com/pagamento')
            ->post('https://seguro.kazakora.com/cookies/aceitar')
            ->assertCookie('_fbc', 'fb.1.1761440000000.IwAR0abcdefghij', false);
    }

    public function test_aceite_nao_troca_o_fbc_que_o_pixel_ja_criou(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withUnencryptedCookie('_fbc', 'fb.1.1.do-pixel')
            ->withSession(['meta_fbclid' => ['valor' => 'IwAR0abcdefghij', 'em' => 1761440000000]])
            ->post('/cookies/aceitar')
            ->assertCookieMissing('_fbc');
    }
}

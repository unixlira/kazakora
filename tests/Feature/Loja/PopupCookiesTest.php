<?php

namespace Tests\Feature\Loja;

use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Modules\Analytics\Models\SiteVisit;
use App\Services\Meta\ConversoesMeta;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Pop-up de cookies "Aceito" / "Somente o básico" (pedido do Lira
 * 2026-11-18, docs/privacidade-e-cookies.md). Os dois botões são honestos:
 * a recusa desliga Pixel e API de Conversões de verdade.
 */
class PopupCookiesTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    private const BASICO = Cookies::PREFIXO_BASICO.Cookies::VERSAO;

    public function test_sem_escolha_o_pop_up_aparece(): void
    {
        $this->get('/politica-de-entrega')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cookies.aceito', false)
            ->where('cookies.escolhido', false));
    }

    public function test_aceito_grava_a_prova_com_a_escolha_e_some_o_pop_up(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)->from('/politica-de-entrega')->post('/cookies/aceitar')
            ->assertRedirect('/politica-de-entrega')
            ->assertCookie(Cookies::COOKIE_OK, Cookies::VERSAO);

        $prova = ConsentimentoCookie::sole();
        $this->assertSame(ConsentimentoCookie::ESCOLHA_ACEITO, $prova->escolha);
        $this->assertSame(Cookies::VERSAO, $prova->versao);
        $this->assertSame('kazakora', $prova->origem);
        $this->assertNotNull($prova->aceito_em);

        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)->get('/politica-de-entrega')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cookies.aceito', true)
                ->where('cookies.escolhido', true));
    }

    public function test_somente_o_basico_grava_a_recusa_e_lembra_dela(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)->from('/politica-de-entrega')->post('/cookies/recusar')
            ->assertRedirect('/politica-de-entrega')
            ->assertCookie(Cookies::COOKIE_OK, self::BASICO);

        $prova = ConsentimentoCookie::sole();
        $this->assertSame(ConsentimentoCookie::ESCOLHA_BASICO, $prova->escolha);
        $this->assertSame(Cookies::VERSAO, $prova->versao);
        $this->assertSame('kazakora', $prova->origem);
        $this->assertNotNull($prova->ip);
        $this->assertSame(self::NAVEGADOR, $prova->user_agent);

        // Pop-up não volta, e o Pixel do navegador não é liberado.
        $this->withCookie(Cookies::COOKIE_OK, self::BASICO)->get('/politica-de-entrega')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cookies.aceito', false)
                ->where('cookies.escolhido', true));
    }

    public function test_somente_o_basico_nao_cria_fbc_nem_conta_visita_com_ok(): void
    {
        config(['app.url' => 'https://kazakora.com']);

        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->withSession(['meta_fbclid' => ['valor' => 'IwAR0abcdefghij', 'em' => 1761440000000]])
            ->from('https://kazakora.com/produtos/fone')
            ->post('https://kazakora.com/cookies/recusar')
            ->assertCookieMissing('_fbc');

        $this->assertSame(0, SiteVisit::where('consentiu', true)->count());
    }

    public function test_quem_ficou_com_o_basico_navega_sem_cookies_de_terceiro_no_log(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, self::BASICO)
            ->withHeader('Cookie', '_fbp=fb.1.1700000000.111')
            ->withHeader('User-Agent', self::NAVEGADOR)
            ->get('/politica-de-entrega')->assertOk();

        $visita = SiteVisit::sole();
        $this->assertFalse($visita->consentiu);
        $this->assertNull($visita->cookies);
        $this->assertNull($visita->user_agent);
    }

    public function test_somente_o_basico_desliga_a_api_de_conversoes(): void
    {
        $basico = Request::create('/');
        $basico->cookies->set(Cookies::COOKIE_OK, self::BASICO);
        $aceito = Request::create('/');
        $aceito->cookies->set(Cookies::COOKIE_OK, Cookies::VERSAO);

        $this->assertFalse(Cookies::aceitou($basico));
        $this->assertTrue(Cookies::recusou($basico));
        $this->assertFalse(app(ConversoesMeta::class)->consentiu($basico));
        $this->assertTrue(app(ConversoesMeta::class)->consentiu($aceito));
    }

    public function test_recusa_repetida_nao_duplica_a_prova(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, self::BASICO)->post('/cookies/recusar');

        $this->assertSame(0, ConsentimentoCookie::count());
    }

    public function test_quem_ficou_com_o_basico_pode_aceitar_depois(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, self::BASICO)->post('/cookies/aceitar')
            ->assertCookie(Cookies::COOKIE_OK, Cookies::VERSAO);

        $this->assertSame(ConsentimentoCookie::ESCOLHA_ACEITO, ConsentimentoCookie::sole()->escolha);
    }

    public function test_quem_aceitou_pode_ficar_com_o_basico_depois(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)->post('/cookies/recusar')
            ->assertCookie(Cookies::COOKIE_OK, self::BASICO);

        $this->assertSame(ConsentimentoCookie::ESCOLHA_BASICO, ConsentimentoCookie::sole()->escolha);
    }

    public function test_aceite_de_2026_11_02_continua_valendo_sem_pop_up(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, '2026-11-02')->get('/politica-de-entrega')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cookies.aceito', true)
                ->where('cookies.escolhido', true));
    }

    public function test_basico_de_versao_antiga_mostra_o_pop_up_de_novo(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, Cookies::PREFIXO_BASICO.'2020-01-01')->get('/politica-de-entrega')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cookies.aceito', false)
                ->where('cookies.escolhido', false));
    }

    public function test_ferrakora_grava_aceite_e_recusa_com_a_origem_dela(): void
    {
        config(['services.ferrakora.origens' => 'ferrakora.devlira.com.br']);
        $visitante = '6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b';

        $this->withHeader('Origin', 'https://ferrakora.devlira.com.br')->withHeader('User-Agent', self::NAVEGADOR)
            ->post('/api/ferrakora/cookies', ['escolha' => 'basico', 'visitante' => $visitante])
            ->assertNoContent();
        $this->withHeader('Origin', 'https://ferrakora.devlira.com.br')->withHeader('User-Agent', self::NAVEGADOR)
            ->post('/api/ferrakora/cookies', ['escolha' => 'aceito', 'visitante' => $visitante])
            ->assertNoContent();

        $provas = ConsentimentoCookie::query()->orderBy('id')->get();
        $this->assertSame(['basico', 'aceito'], $provas->pluck('escolha')->all());
        $this->assertSame(['ferrakora'], $provas->pluck('origem')->unique()->values()->all());
        $this->assertSame([$visitante], $provas->pluck('visitante_id')->unique()->values()->all());
        $this->assertSame([Cookies::VERSAO], $provas->pluck('versao')->unique()->values()->all());
    }

    public function test_ferrakora_recusa_dominio_de_fora_e_escolha_invalida(): void
    {
        config(['services.ferrakora.origens' => 'ferrakora.devlira.com.br']);

        $this->withHeader('Origin', 'https://outro.invalid')->post('/api/ferrakora/cookies', ['escolha' => 'aceito'])->assertStatus(403);
        $this->withHeader('Origin', 'https://ferrakora.devlira.com.br')->post('/api/ferrakora/cookies', ['escolha' => 'tudo'])->assertStatus(422);

        $this->assertSame(0, ConsentimentoCookie::count());
    }
}

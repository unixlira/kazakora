<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Analytics\Models\ConsentimentoCookie;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * POST /api/{site}/cookies (2026-11-19): o aviso de cookies da FerraKora
 * (ferrakora.com) grava aqui a prova da escolha. A loja KazaKora não muda.
 */
class CookiesFerraKoraApiTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ferrakora.origens' => 'ferrakora.com,ferrakora.devlira.com.br']);
    }

    public function test_aceito_grava_a_prova_com_origem_ferrakora(): void
    {
        $visitante = '0b9c4c8e-5d2a-4f7e-9a51-3c6e2d1f8a77';

        $this->withHeader('Origin', 'https://www.ferrakora.com')->withHeader('User-Agent', self::NAVEGADOR)
            ->post('/api/ferrakora/cookies', ['escolha' => 'aceito', 'visitante' => $visitante])
            ->assertNoContent();

        $prova = ConsentimentoCookie::sole();
        $this->assertSame('aceito', $prova->escolha);
        $this->assertSame('ferrakora', $prova->origem);
        $this->assertSame(Cookies::VERSAO, $prova->versao);
        $this->assertSame($visitante, $prova->visitante_id);
        $this->assertSame(self::NAVEGADOR, $prova->user_agent);
        $this->assertNotNull($prova->aceito_em);
    }

    public function test_basico_grava_a_recusa_sem_cookie_da_loja(): void
    {
        $resposta = $this->withHeader('Origin', 'https://ferrakora.com')
            ->post('/api/ferrakora/cookies', ['escolha' => 'basico']);

        $resposta->assertNoContent()->assertCookieMissing(Cookies::COOKIE_OK);

        $prova = ConsentimentoCookie::sole();
        $this->assertSame('basico', $prova->escolha);
        $this->assertSame('ferrakora', $prova->origem);
        $this->assertSame(36, strlen($prova->visitante_id));
    }

    public function test_site_invalido_dominio_de_fora_e_escolha_invalida(): void
    {
        $this->withHeader('Origin', 'https://ferrakora.com')->post('/api/outrosite/cookies', ['escolha' => 'aceito'])->assertNotFound();
        $this->withHeader('Origin', 'https://outro.invalid')->post('/api/ferrakora/cookies', ['escolha' => 'aceito'])->assertStatus(403);
        $this->post('/api/ferrakora/cookies', ['escolha' => 'aceito'])->assertStatus(403);
        $this->withHeader('Origin', 'https://ferrakora.com')->post('/api/ferrakora/cookies', ['escolha' => 'tudo'])->assertStatus(422);

        $this->assertSame(0, ConsentimentoCookie::count());
    }

    public function test_throttle_de_20_por_minuto(): void
    {
        RateLimiter::clear('outro-site-cookies');

        for ($i = 0; $i < 20; $i++) {
            $this->withHeader('Origin', 'https://ferrakora.com')->post('/api/ferrakora/cookies', ['escolha' => 'aceito'])->assertNoContent();
        }

        $this->withHeader('Origin', 'https://ferrakora.com')->post('/api/ferrakora/cookies', ['escolha' => 'aceito'])->assertStatus(429);
        $this->assertSame(20, ConsentimentoCookie::count());
    }

    public function test_aceite_da_loja_continua_com_a_versao_de_sempre(): void
    {
        $this->assertSame('2026-11-02', Cookies::VERSAO);

        $this->from('/politica-de-entrega')->post('/cookies/aceitar')->assertCookie(Cookies::COOKIE_OK, Cookies::VERSAO);
        $this->assertSame('aceito', ConsentimentoCookie::sole()->escolha);
        $this->assertSame('kazakora', ConsentimentoCookie::sole()->origem);
    }
}

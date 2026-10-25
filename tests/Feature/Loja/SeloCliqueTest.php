<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cliques nos selos de segurança do rodapé (pedido 2026-10-25): POST
 * /selos/clique soma na última visita do visitante; começa em zero.
 */
class SeloCliqueTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        $this->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);
    }

    private function visita(string $visitante = 'v-1'): SiteVisit
    {
        return SiteVisit::create(['site' => 'kazakora', 'visitor_id' => $visitante, 'path' => '/', 'ip' => '1.2.3.4', 'consentiu' => true]);
    }

    private function clicar(array $corpo, string $agente = self::NAVEGADOR, string $visitante = 'v-1'): TestResponse
    {
        return $this->call('POST', '/selos/clique', [], [Cookies::COOKIE_OK => Cookies::VERSAO, Cookies::COOKIE_VISITANTE => $visitante], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_USER_AGENT' => $agente,
        ], json_encode($corpo));
    }

    public function test_visita_nova_comeca_com_zero(): void
    {
        $v = $this->visita()->fresh();
        $this->assertSame([0, 0, 0], [$v->cliques_selo_google, $v->cliques_selo_reclame_aqui, $v->cliques_selo_norton]);
    }

    public function test_clique_soma_so_no_selo_certo_da_ultima_visita(): void
    {
        $antiga = $this->visita();
        $nova = $this->visita();
        $this->visita('outro');

        $this->clicar(['selo' => 'norton'])->assertNoContent();
        $this->clicar(['selo' => 'norton'])->assertNoContent();
        $this->clicar(['selo' => 'google'])->assertNoContent();

        $this->assertSame(0, $antiga->fresh()->cliques_selo_norton);
        $this->assertSame(2, $nova->fresh()->cliques_selo_norton);
        $this->assertSame(1, $nova->fresh()->cliques_selo_google);
        $this->assertSame(0, $nova->fresh()->cliques_selo_reclame_aqui);
        $this->assertSame(0, SiteVisit::where('visitor_id', 'outro')->sum('cliques_selo_norton'));
    }

    public function test_recusa_selo_desconhecido(): void
    {
        $v = $this->visita();
        $this->clicar(['selo' => 'cliques_selo_norton = 99'])->assertStatus(422);
        $this->assertSame(0, $v->fresh()->cliques_selo_norton);
    }

    public function test_robo_e_equipe_nao_contam(): void
    {
        $v = $this->visita();
        $this->clicar(['selo' => 'google'], 'Googlebot/2.1')->assertNoContent();
        $this->assertSame(0, $v->fresh()->cliques_selo_google);

        $equipe = User::factory()->create(['role' => User::STAFF_ROLES[0]]);
        $this->actingAs($equipe)->call('POST', '/selos/clique', [], [Cookies::COOKIE_OK => Cookies::VERSAO, Cookies::COOKIE_VISITANTE => 'v-1'], [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => self::NAVEGADOR,
        ], json_encode(['selo' => 'google']))->assertNoContent();
        $this->assertSame(0, $v->fresh()->cliques_selo_google);
    }

    public function test_outra_origem_recusada(): void
    {
        $this->clicar(['selo' => 'google'])->assertStatus(204);
        $this->call('POST', '/selos/clique', [], [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_USER_AGENT' => self::NAVEGADOR], '{"selo":"google"}')->assertStatus(403);
    }
}

<?php

namespace Tests\Feature\Checkout;

use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Medição da tela de pagamento da FerraKora (pedido do Lira 2026-11-14):
 * POST /api/ferrakora/checkout/eventos grava os mesmos eventos da loja com
 * site = ferrakora, só de quem vem do site de lá, e o checkout:funil separa
 * os dois sites.
 */
class OutroSiteCheckoutEventosTest extends TestCase
{
    use RefreshDatabase;

    private const CELULAR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ferrakora.origens' => 'ferrakora.com, ferrakora.devlira.com.br']);
    }

    private function enviar(array|string $corpo, array $cabecalhos = [], string $site = 'ferrakora'): TestResponse
    {
        return $this->call('POST', "/api/{$site}/checkout/eventos", [], [], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_USER_AGENT' => self::CELULAR,
            'HTTP_ORIGIN' => 'https://ferrakora.com',
            'REMOTE_ADDR' => '201.10.20.30',
            ...$cabecalhos,
        ], is_string($corpo) ? $corpo : json_encode($corpo));
    }

    public function test_grava_o_lote_com_o_site_e_o_visitante_das_visitas(): void
    {
        $visitante = substr(md5('201.10.20.30'), 0, 32);
        SiteVisit::query()->create(['site' => 'ferrakora', 'visitor_id' => $visitante, 'path' => '/produto/x', 'origem' => 'meta_ads']);
        SiteVisit::query()->create(['site' => 'ferrakora', 'visitor_id' => $visitante, 'path' => '/seguro-pagamento', 'origem' => 'interno']);

        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [
            ['evento' => 'abriu'],
            ['evento' => 'viu_formulario'],
            ['evento' => 'foco', 'campo' => 'nome'],
            ['evento' => 'preencheu', 'campo' => 'nome'],
            ['evento' => 'cep_respondido'],
            ['evento' => 'pagamento', 'campo' => 'pix'],
            ['evento' => 'saiu', 'segundos' => 15, 'rolagem' => 50],
        ]])->assertNoContent();

        $this->assertSame(7, CheckoutEvento::where('site', 'ferrakora')->count());
        $abriu = CheckoutEvento::firstWhere('evento', 'abriu');
        $this->assertSame($visitante, $abriu->visitor_id);
        $this->assertSame('meta_ads', $abriu->origem);
        $this->assertSame('celular', $abriu->dispositivo);
        $this->assertSame('abc123def456', $abriu->pagina_id);
        $this->assertNull($abriu->user_id);
        $saiu = CheckoutEvento::firstWhere('evento', 'saiu');
        $this->assertSame(15, $saiu->valor);
        $this->assertSame(50, $saiu->rolagem);
    }

    public function test_so_aceita_do_dominio_do_site(): void
    {
        $lote = ['pagina' => 'abc123def456', 'eventos' => [['evento' => 'abriu']]];

        $this->enviar($lote, ['HTTP_ORIGIN' => 'https://outro-site.com'])->assertStatus(403);
        $this->enviar($lote, ['HTTP_ORIGIN' => ''])->assertStatus(403);
        $this->enviar($lote, [], 'outrosite')->assertNotFound();
        $this->assertSame(0, CheckoutEvento::count());

        // Sem Origin, vale o Referer.
        $this->enviar($lote, ['HTTP_ORIGIN' => '', 'HTTP_REFERER' => 'https://www.ferrakora.com/seguro-pagamento'])->assertNoContent();
        $this->assertSame(1, CheckoutEvento::count());
    }

    public function test_nunca_grava_o_valor_digitado_nem_lote_fora_do_formato(): void
    {
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [
            ['evento' => 'preencheu', 'campo' => 'nome', 'valor' => 'Maria da Silva'],
        ]])->assertStatus(422);
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [
            ['evento' => 'preencheu', 'campo' => '123.456.789-09'],
        ]])->assertStatus(422);
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [['evento' => 'pedido_criado']]])->assertStatus(422);
        $this->enviar('isto não é json')->assertStatus(422);
        $this->enviar(str_repeat('a', 9000))->assertStatus(413);

        $this->assertSame(0, CheckoutEvento::count());
    }

    public function test_robo_recebe_204_e_nao_grava(): void
    {
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [['evento' => 'abriu']]], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->assertNoContent();
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [['evento' => 'abriu']]], ['HTTP_USER_AGENT' => 'curl/8.0'])
            ->assertNoContent();

        $this->assertSame(0, CheckoutEvento::count());
    }

    public function test_funil_separa_a_loja_da_ferrakora(): void
    {
        CheckoutEvento::create(['visitor_id' => 'loja1', 'evento' => 'abriu', 'dispositivo' => 'celular']);
        foreach (['f1', 'f2', 'f3'] as $v) {
            CheckoutEvento::create(['site' => 'ferrakora', 'visitor_id' => $v, 'evento' => 'abriu', 'dispositivo' => 'celular']);
        }
        CheckoutEvento::create(['site' => 'ferrakora', 'visitor_id' => 'f1', 'evento' => 'preencheu', 'campo' => 'nome', 'dispositivo' => 'celular']);
        CheckoutEvento::pedidoCriadoEmOutroSite(request(), 'ferrakora');

        $this->artisan('checkout:funil', ['--site' => 'ferrakora'])
            ->expectsOutputToContain('Funil do checkout (ferrakora)')
            ->expectsOutputToContain('Sem digitar nada')
            ->assertSuccessful();
        $this->assertSame(1, CheckoutEvento::where('site', 'ferrakora')->where('evento', CheckoutEvento::PEDIDO_CRIADO)->count());

        // A loja continua contando só as linhas dela (site nulo).
        $this->artisan('checkout:funil')
            ->expectsOutputToContain('Funil do checkout de')
            ->expectsTable(['Quem saiu sem pedido', 'celular', 'total'], [
                ['Visitantes', 1, 1],
                ['Mediana de tempo ativo (s)', '-', '-'],
                ['Mediana de rolagem (%)', '0', '0'],
                ['Sem digitar nada', 1, 1],
            ])
            ->assertSuccessful();
    }
}

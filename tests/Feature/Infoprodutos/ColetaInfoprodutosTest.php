<?php

namespace Tests\Feature\Infoprodutos;

use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Coleta anônima das páginas de infoproduto e de produto das lojas
 * (POST /api-infoprodutos/evento, 2026-11-19): valida o corpo, aceita só os
 * domínios medidos e NUNCA grava IP.
 */
class ColetaInfoprodutosTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api-infoprodutos/evento';

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

    private function enviar(array $corpo, string $origem = 'https://digitalmktseller.com', string $ip = '203.0.113.77')
    {
        return $this->call('POST', self::URL, [], [], [], [
            'HTTP_ORIGIN' => $origem,
            'HTTP_USER_AGENT' => self::UA,
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'REMOTE_ADDR' => $ip,
        ], json_encode($corpo));
    }

    private function corpo(array $extra = []): array
    {
        return array_replace([
            's' => 'AbCdEf0123456789xyz',
            'p' => 'pidelo-bien',
            'pg' => 'landing',
            'l' => 'es',
            'e' => [['t' => 'scroll', 'v' => 50, 'ms' => 3200], ['t' => 'buy', 'v' => 'hero', 'ms' => 9000]],
            'pv' => ['r' => 'l.facebook.com', 'us' => 'facebook', 'um' => 'paid', 'uc' => 'pidelo-frio', 'ut' => '120211', 'uk' => '', 'fb' => 1],
        ], $extra);
    }

    public function test_grava_os_eventos_sem_guardar_o_ip(): void
    {
        $this->enviar($this->corpo())->assertNoContent();

        $this->assertSame(3, InfoprodutoEvento::count());
        $pv = InfoprodutoEvento::where('tipo', 'pageview')->first();
        $this->assertSame('facebook', $pv->utm_source);
        $this->assertSame('120211', $pv->utm_content);
        $this->assertTrue($pv->fbclid);
        $this->assertSame('l.facebook.com', $pv->referrer);
        $this->assertSame('celular', $pv->dispositivo);
        $this->assertFalse($pv->teste);

        // Nenhuma coluna de IP e o IP não aparece em lugar nenhum da linha.
        $this->assertNotContains('ip', Schema::getColumnListing('infoproduto_eventos'));
        foreach (DB::table('infoproduto_eventos')->get() as $linha) {
            $this->assertStringNotContainsString('203.0.113.77', json_encode($linha));
        }
    }

    public function test_pais_so_vem_da_cloudflare(): void
    {
        $this->call('POST', self::URL, [], [], [], [
            'HTTP_ORIGIN' => 'https://digitalmktseller.com', 'HTTP_USER_AGENT' => self::UA,
            'HTTP_CF_IPCOUNTRY' => 'MX', 'REMOTE_ADDR' => '203.0.113.77',
        ], json_encode($this->corpo()))->assertNoContent();

        // Chamada que não veio de IP da Cloudflare não escolhe o país.
        $this->assertNull(InfoprodutoEvento::where('tipo', 'pageview')->value('pais'));
    }

    public function test_recusa_origem_de_fora(): void
    {
        $this->enviar($this->corpo(), 'https://outro-site.com')->assertStatus(403);
        $this->assertSame(0, InfoprodutoEvento::count());
    }

    public function test_cors_so_para_os_dominios_medidos(): void
    {
        $this->call('OPTIONS', self::URL, [], [], [], ['HTTP_ORIGIN' => 'https://digitalmktseller.com'])
            ->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', 'https://digitalmktseller.com');
        $r = $this->call('OPTIONS', self::URL, [], [], [], ['HTTP_ORIGIN' => 'https://malicioso.com']);
        $r->assertStatus(403);
        $this->assertFalse($r->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_recusa_corpo_fora_da_lista(): void
    {
        $this->enviar($this->corpo(['e' => [['t' => 'digitou', 'v' => 'meu email']]]))->assertStatus(422);
        $this->enviar($this->corpo(['e' => [['t' => 'scroll', 'v' => 33]]]))->assertStatus(422);
        $this->enviar($this->corpo(['email' => 'x@y.com']))->assertStatus(422);
        $this->enviar($this->corpo(['p' => 'produto-que-nao-existe']))->assertStatus(422);
        $this->enviar($this->corpo(['s' => 'curto']))->assertStatus(422);
        $this->enviar($this->corpo(['pv' => ['r' => 'x.com', 'fb' => 1, 'fbclid' => 'IwAR123']]))->assertStatus(422);
        $this->assertSame(0, InfoprodutoEvento::count());
    }

    public function test_robo_nao_e_gravado(): void
    {
        $this->call('POST', self::URL, [], [], [], [
            'HTTP_ORIGIN' => 'https://digitalmktseller.com', 'HTTP_USER_AGENT' => 'Mozilla/5.0 HeadlessChrome/120',
        ], json_encode($this->corpo()))->assertNoContent();
        $this->assertSame(0, InfoprodutoEvento::count());
    }

    public function test_visita_de_teste_fica_marcada_na_sessao_inteira(): void
    {
        $this->enviar($this->corpo(['pv' => ['us' => 'teste-medicao', 'fb' => 0, 'r' => '']]))->assertNoContent();
        $this->enviar($this->corpo(['pv' => null, 'e' => [['t' => 'play', 'ms' => 100]]]))->assertNoContent();

        $this->assertSame(4, InfoprodutoEvento::where('teste', true)->count());
    }

    public function test_clique_do_mapa_e_pagina_de_produto_da_loja(): void
    {
        $this->enviar([
            's' => 'Sessao0123456789AB', 'p' => 'kz/arvore-de-natal', 'pg' => 'produto', 'l' => 'pt',
            'e' => [
                ['t' => 'clk', 'x' => 48, 'y' => 1210, 'w' => 390, 'k' => 'b', 'sec' => 'comprar', 'el' => 'COMPRAR AGORA', 'n' => 'comprar', 'ms' => 5000],
                ['t' => 'clk', 'x' => 10, 'y' => 300, 'w' => 390, 'k' => 'm', 'sec' => '', 'el' => 'Frete grátis', 'ms' => 6000],
                ['t' => 'acao', 'v' => 'galeria:3', 'ms' => 7000],
                ['t' => 'exit', 'v' => 42, 'sm' => 80, 'ms' => 42000],
            ],
        ], 'https://kazakora.com')->assertNoContent();

        $clique = InfoprodutoEvento::where('tipo', 'clk')->where('alvo', 'b')->first();
        $this->assertSame([48, 1210, 390, 'comprar', 'COMPRAR AGORA'], [$clique->x, $clique->y, $clique->largura, $clique->nome, $clique->elemento]);
        $this->assertSame(80, InfoprodutoEvento::where('tipo', 'exit')->value('rolagem_max'));
        $this->assertSame('galeria:3', InfoprodutoEvento::where('tipo', 'acao')->value('valor'));

        // Clique com x fora de 0-100 ou nome com caractere estranho = 422.
        $this->enviar(['s' => 'Sessao0123456789AB', 'p' => 'kz/x', 'pg' => 'produto', 'e' => [['t' => 'clk', 'x' => 140, 'y' => 1, 'w' => 390, 'k' => 'a']]], 'https://kazakora.com')->assertStatus(422);
        $this->enviar(['s' => 'Sessao0123456789AB', 'p' => 'kz/x', 'pg' => 'produto', 'e' => [['t' => 'acao', 'v' => '<script>']]], 'https://kazakora.com')->assertStatus(422);
    }

    public function test_clique_sem_ponteiro_vai_com_origem_e_sem_posicao_inventada(): void
    {
        $this->enviar([
            's' => 'Sessao0123456789AC', 'p' => 'kz/arvore-de-natal', 'pg' => 'produto', 'l' => 'pt',
            'e' => [
                // Enter no teclado: centro do alvo + origem t.
                ['t' => 'clk', 'x' => 24, 'y' => 574, 'w' => 1280, 'k' => 'a', 'n' => 'comprar', 'o' => 't', 'ms' => 1000],
                // el.click() de robô em alvo escondido: sem posição (null no JSON).
                ['t' => 'clk', 'x' => null, 'y' => null, 'w' => 1050, 'k' => 'a', 'el' => 'Saltar al contenido', 'o' => 'p', 'ms' => 1001],
                // Script antigo: 0,0 = posição desconhecida.
                ['t' => 'clk', 'x' => 0, 'y' => 0, 'w' => 1050, 'k' => 'a', 'el' => 'Privacidad', 'ms' => 1002],
            ],
        ], 'https://kazakora.com')->assertNoContent();

        $cliques = InfoprodutoEvento::where('tipo', 'clk')->orderBy('ms')->get();
        $this->assertSame([[24, 574, 't'], [null, null, 'p'], [null, null, null]], $cliques->map(fn ($c) => [$c->x, $c->y, $c->origem])->all());

        // Origem fora da lista = 422.
        $this->enviar(['s' => 'Sessao0123456789AC', 'p' => 'kz/x', 'pg' => 'produto', 'e' => [['t' => 'clk', 'x' => 1, 'y' => 1, 'w' => 390, 'k' => 'a', 'o' => 'x']]], 'https://kazakora.com')->assertStatus(422);
    }
}

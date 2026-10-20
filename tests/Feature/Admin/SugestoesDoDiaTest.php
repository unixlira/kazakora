<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Jobs\GerarSugestoesJob;
use App\Modules\Marketplace\Models\PrecoConcorrente;
use App\Modules\Marketplace\Models\Sugestao;
use App\Modules\Marketplace\Support\Sugestoes\DiagnosticoMetaAds;
use App\Modules\Marketplace\Support\Sugestoes\GeradorDeSugestoes;
use App\Modules\Marketplace\Support\Sugestoes\PesquisaDePrecos;
use App\Modules\Marketplace\Support\Sugestoes\RegrasDePreco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sugestões do dia (pedido do Lira 2026-10-20): rotina da madrugada que lê o
 * Meta Ads, pesquisa preço de concorrente e monta o board de Indicadores.
 */
class SugestoesDoDiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_ads.access_token' => 'token-secreto',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
            'loja.sugestoes_pausa_ms' => 0,
        ]);
    }

    private function zoomHtml(array $hits): string
    {
        $dados = ['props' => ['initialReduxState' => ['hits' => ['hits' => $hits]]]];

        return '<html><script id="__NEXT_DATA__" type="application/json">'.json_encode($dados).'</script></html>';
    }

    private function caixa(array $extra = []): Product
    {
        return Product::factory()->create($extra + [
            'name' => 'Caixa Sanfonada de Ferramentas Profissional 168 Peças Cromo Vanádio',
            'slug' => 'caixa-sanfonada-168',
            'price' => 502.32,
            'cost_price' => 250,
            'is_active' => true,
            'stock' => 10,
        ]);
    }

    public function test_zoom_fica_so_com_o_mesmo_produto(): void
    {
        $html = $this->zoomHtml([
            ['name' => 'Caixa Sanfonada Ferramentas 168 Peças Cromo Vanádio', 'price' => 449.9, 'merchantName' => 'Magazine Luiza', 'url' => '/lead?oid=1'],
            // Outro produto: 60 peças.
            ['name' => 'Caixa Sanfonada Ferramentas 60 Peças Tramontina', 'price' => 480, 'merchantName' => 'Amazon', 'url' => '/lead?oid=2'],
            // Nada a ver.
            ['name' => 'Furadeira de Impacto 650W', 'price' => 300, 'merchantName' => 'Americanas', 'url' => '/lead?oid=3'],
            // A própria loja.
            ['name' => 'Caixa Sanfonada Ferramentas 168 Peças', 'price' => 502.32, 'merchantName' => 'KazaKora', 'url' => '/lead?oid=4'],
        ]);

        $ofertas = PesquisaDePrecos::ofertasDoZoom($html, 'Caixa Sanfonada de Ferramentas Profissional 168 Peças Cromo Vanádio');

        $this->assertCount(1, $ofertas);
        $this->assertSame('Magazine Luiza', $ofertas[0]['loja']);
        $this->assertSame(449.9, $ofertas[0]['preco']);
        $this->assertSame('https://www.zoom.com.br/lead?oid=1', $ofertas[0]['link']);
    }

    public function test_zoom_sem_lista_vira_erro_da_fonte(): void
    {
        Http::fake(['www.zoom.com.br/*' => Http::response('<html>bloqueado</html>')]);
        $produto = $this->caixa();

        $pesquisa = app(PesquisaDePrecos::class);
        $this->assertSame([], $pesquisa->pesquisar($produto));
        $this->assertFalse($pesquisa->fontes()['zoom']['ok']);
        $this->assertStringContainsString('Zoom mudou a página', $pesquisa->fontes()['zoom']['erro']);
    }

    public function test_preco_terminado_em_90(): void
    {
        $this->assertSame(488.9, RegrasDePreco::terminaEm90(489.00));
        $this->assertSame(489.9, RegrasDePreco::terminaEm90(489.95));
        $this->assertSame(488.9, RegrasDePreco::terminaEm90(489.90));
    }

    public function test_regras_de_preco(): void
    {
        $produto = $this->caixa();
        $regras = new RegrasDePreco;
        $oferta = fn (float $preco, string $loja = 'Magazine Luiza') => ['fonte' => 'zoom', 'loja' => $loja, 'titulo' => 'x', 'preco' => $preco, 'link' => null];

        // Concorrente 10% mais barato e acima do piso (250 / 0,56 = 446,43): baixar.
        $baixar = $regras->avaliar($produto, [$oferta(452.00)]);
        $this->assertSame('baixar', $baixar[0]['tipo']);
        $this->assertSame(451.9, $baixar[0]['valor']);
        $this->assertStringContainsString('Magazine Luiza vende por R$ 452,00', $baixar[0]['acao']);

        // Abaixo do piso: não acompanhar.
        $piso = $regras->avaliar($produto, [$oferta(400.00)]);
        $this->assertSame('abaixo_do_piso', $piso[0]['tipo']);
        $this->assertSame(3, $piso[0]['prioridade']);

        // Todos bem acima: subir até um pouco abaixo do mais barato.
        $subir = $regras->avaliar($produto, [$oferta(600.00), $oferta(650.00, 'Amazon')]);
        $this->assertSame('subir', $subir[0]['tipo']);
        $this->assertSame(581.9, $subir[0]['valor']);

        // Só um concorrente acima: não basta para subir.
        $this->assertSame([], $regras->avaliar($produto, [$oferta(600.00)]));

        // Site mais caro que a nossa Shopee.
        $shopee = $regras->avaliar($produto, [], 489.90);
        $this->assertSame('site_mais_caro_que_shopee', $shopee[0]['tipo']);
    }

    public function test_criativo_por_anuncio(): void
    {
        $diag = new DiagnosticoMetaAds;
        $anuncio = ['ad_id' => '1', 'nome' => 'Caixa anúncio 1', 'formato' => 'video', 'impressoes' => 2000, 'video_3s' => 800,
            'ctr_link' => 0.6, 'frequencia' => 1.2, 'cpm' => 30, 'gasto' => 60];

        $s = $diag->doAnuncio($anuncio);
        $this->assertSame('ctr_baixo', $s['tipo']);
        $this->assertSame('criativo:ctr:1', $s['chave']);
        $this->assertStringContainsString('3 primeiros segundos', $s['acao']);

        // Vídeo que perde gente no começo vem antes do CTR.
        $this->assertSame('gancho_fraco', $diag->doAnuncio(['video_3s' => 200] + $anuncio)['tipo']);

        // Carrossel com CTR baixo: trocar o carrossel.
        $this->assertStringContainsString('Troque o carrossel', $diag->doAnuncio(['formato' => 'carrossel'] + $anuncio)['acao']);

        // Amostra pequena: sem veredito.
        $this->assertNull($diag->doAnuncio(['impressoes' => 500] + $anuncio));

        // Anúncio saudável.
        $this->assertNull($diag->doAnuncio(['ctr_link' => 2.5] + $anuncio));
    }

    private function produtoMeta(array $extra = []): array
    {
        return $extra + [
            'product_id' => 1, 'nome' => 'Caixa 168', 'preco' => 500.0, 'gasto' => 80.0, 'cliques_link' => 70, 'carrinhos' => 0,
            'compras' => 0, 'vendas_meta' => 0.0, 'visitantes' => 65, 'consentidos' => 9, 'vendas_site' => 0, 'receita_site' => 0.0,
            'roas_empate' => 4.0, 'margem_estimada' => false, 'checagens' => ['a galeria tem fotos do produto "Kit 85 peças"'], 'mais_barato' => null,
        ];
    }

    public function test_chega_e_nao_compra_aponta_a_pagina_ou_o_preco(): void
    {
        $diag = new DiagnosticoMetaAds;

        $pagina = collect($diag->doProduto($this->produtoMeta()))->keyBy('tipo');
        $this->assertStringContainsString('galeria tem fotos do produto "Kit 85 peças"', $pagina['nao_compram']['acao']);
        $this->assertSame(1, $pagina['nao_compram']['prioridade']);

        // Com concorrente mais barato, é preço (mesma chave da regra de preço).
        $preco = collect($diag->doProduto($this->produtoMeta(['mais_barato' => ['loja' => 'Magalu', 'preco' => 430.0]])))->keyBy('tipo');
        $this->assertSame('preco:baixar:1', $preco['nao_compram_preco']['chave']);
        $this->assertStringContainsString('Magalu vende por R$ 430,00', $preco['nao_compram_preco']['acao']);
    }

    public function test_prejuizo_e_escalar(): void
    {
        $diag = new DiagnosticoMetaAds;

        // Lucro por venda 500 / 4 = 125; gastou 300 (mais de 2 vendas de lucro) e vendeu 500: ROAS 1,67 < 4.
        $prejuizo = collect($diag->doProduto($this->produtoMeta(['gasto' => 300.0, 'vendas_site' => 1, 'receita_site' => 500.0, 'visitantes' => 10, 'cliques_link' => 10])))->keyBy('tipo');
        $this->assertArrayHasKey('prejuizo', $prejuizo->all());

        // Gastou pouco ainda: sem veredito de prejuízo.
        $cedo = collect($diag->doProduto($this->produtoMeta(['gasto' => 100.0, 'visitantes' => 10, 'cliques_link' => 10])))->keyBy('tipo');
        $this->assertArrayNotHasKey('prejuizo', $cedo->all());

        // ROAS da Meta 8 com empate 4 e 4 compras: escalar.
        $escalar = collect($diag->doProduto($this->produtoMeta(['gasto' => 250.0, 'compras' => 4, 'carrinhos' => 6, 'vendas_meta' => 2000.0,
            'vendas_site' => 4, 'receita_site' => 2000.0, 'visitantes' => 10, 'cliques_link' => 10])))->keyBy('tipo');
        $this->assertArrayHasKey('escalar', $escalar->all());
    }

    private function fakeMetaEZoom(string $slug): void
    {
        Http::fake([
            'graph.facebook.com/v23.0/act_123/insights*' => Http::response(['data' => [[
                'ad_id' => '555', 'ad_name' => 'Caixa anúncio 2', 'campaign_id' => '9', 'campaign_name' => 'Caixa',
                'spend' => '80.00', 'impressions' => '3000', 'reach' => '2500', 'frequency' => '1.2', 'cpm' => '26.6',
                'clicks' => '100', 'inline_link_clicks' => '75', 'cost_per_inline_link_click' => '1.06',
                'actions' => [['action_type' => 'link_click', 'value' => '75'], ['action_type' => 'video_view', 'value' => '1200'],
                    ['action_type' => 'landing_page_view', 'value' => '8']],
            ]]]),
            'graph.facebook.com/v23.0/act_123/ads*' => Http::response(['data' => [[
                'id' => '555', 'effective_status' => 'ACTIVE',
                'creative' => ['object_type' => 'VIDEO', 'thumbnail_url' => 'https://x/t.jpg',
                    'object_story_spec' => ['video_data' => ['call_to_action' => ['value' => ['link' => "https://kazakora.com/produtos/{$slug}?utm_source=meta"]]]]],
            ]]]),
            'www.zoom.com.br/*' => Http::response($this->zoomHtml([
                ['name' => 'Caixa Sanfonada Ferramentas 168 Peças Cromo Vanádio', 'price' => 452.0, 'merchantName' => 'Magazine Luiza', 'url' => '/lead?oid=1'],
            ])),
        ]);
    }

    private function visitasDoMeta(Product $produto, int $quantas): void
    {
        $ontem = Carbon::yesterday()->setTime(15, 0);
        for ($i = 0; $i < $quantas; $i++) {
            DB::table('site_visits')->insert([
                'site' => 'kazakora', 'visitor_id' => 'v'.$i, 'path' => '/produtos/'.$produto->slug, 'origem' => 'meta_ads',
                'consentiu' => $i < 8, 'created_at' => $ontem,
            ]);
        }
    }

    public function test_rotina_cruza_meta_e_concorrente_e_monta_o_board(): void
    {
        $produto = $this->caixa();
        $this->visitasDoMeta($produto, 70);
        $this->fakeMetaEZoom($produto->slug);

        $this->artisan('sugestoes:gerar')->assertSuccessful();

        // 70 visitantes do anúncio, nenhuma venda e Magalu mais barata: a sugestão é de preço, urgente.
        $preco = Sugestao::query()->where('chave', 'preco:baixar:'.$produto->id)->sole();
        $this->assertSame(Sugestao::AREA_PRECO, $preco->area);
        $this->assertSame(1, $preco->prioridade);
        $this->assertStringContainsString('Magazine Luiza vende por R$ 452,00', $preco->acao);
        $this->assertSame(1, PrecoConcorrente::query()->where('product_id', $produto->id)->count());

        // Clicaram 75 e chegaram 70 no site: o Pixel (8) não manda acusar a página.
        $this->assertFalse(Sugestao::query()->where('tipo', 'chegada_baixa')->exists());

        // Rodar de novo no mesmo dia não duplica.
        $this->artisan('sugestoes:gerar')->assertSuccessful();
        $this->assertSame(1, Sugestao::query()->where('chave', 'preco:baixar:'.$produto->id)->count());

        // Ignorada não volta (a rodada de novo refez a aberta: busca outra vez).
        Sugestao::query()->where('chave', 'preco:baixar:'.$produto->id)->sole()->update(['status' => Sugestao::STATUS_IGNORADA, 'resolvida_em' => now()]);
        app(GeradorDeSugestoes::class)->gerar(Carbon::tomorrow());
        $this->assertSame(0, Sugestao::query()->whereDate('dia', Carbon::tomorrow())->where('chave', 'preco:baixar:'.$produto->id)->count());

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com') && $r->method() !== 'GET');
    }

    public function test_board_na_aba_de_indicadores_e_botoes(): void
    {
        Queue::fake();
        $produto = $this->caixa();
        $s = Sugestao::query()->create([
            'dia' => today()->toDateString(), 'chave' => 'preco:baixar:'.$produto->id, 'area' => 'preco', 'tipo' => 'baixar',
            'prioridade' => 1, 'titulo' => $produto->name, 'acao' => 'Baixe para R$ 451,90', 'product_id' => $produto->id,
            'dados' => ['metricas' => ['Seu preço' => 'R$ 502,32', 'Custo' => 'R$ 250,00']],
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/indicadores?aba=sugestoes')->assertOk()->assertInertia(fn ($page) => $page
            ->where('sugestoes.areas.0.chave', 'preco')
            ->where('sugestoes.areas.0.sugestoes.0.acao', 'Baixe para R$ 451,90')
            ->where('sugestoes.areas.0.sugestoes.0.links.0.href', '/admin/produtos/'.$produto->id.'/editar'));

        $this->actingAs($admin)->post("/admin/sugestoes/{$s->id}/status", ['status' => 'feita'])->assertRedirect();
        $this->assertSame('feita', $s->refresh()->status);
        $this->assertSame($admin->id, $s->resolvida_por);

        $this->actingAs($admin)->post('/admin/sugestoes/rodar')->assertRedirect();
        Queue::assertPushed(GerarSugestoesJob::class);
    }

    public function test_quem_nao_ve_financeiro_nao_ve_custo(): void
    {
        $s = Sugestao::query()->create([
            'dia' => today()->toDateString(), 'chave' => 'preco:baixar:1', 'area' => 'preco', 'tipo' => 'baixar', 'prioridade' => 1,
            'titulo' => 'Caixa', 'acao' => 'Baixe', 'dados' => ['metricas' => ['Seu preço' => 'R$ 502,32', 'Custo' => 'R$ 250,00']],
        ]);

        $board = app(\App\Modules\Marketplace\Support\Sugestoes\BoardDeSugestoes::class)->montar(false);
        $metricas = $board['areas'][0]['sugestoes'][0]['metricas'];
        $this->assertArrayHasKey('Seu preço', $metricas);
        $this->assertArrayNotHasKey('Custo', $metricas);
        $this->assertSame($s->id, $board['areas'][0]['sugestoes'][0]['id']);
    }
}

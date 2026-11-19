<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Colunas do funil na tela de campanhas da Meta (pedido do Lira 2026-11-18):
 * Clique > Página > Carrinho > Checkout > Venda, em qualquer loja, com a
 * linha de total do período recalculada a partir das somas.
 *
 * Graph API falsa: a campanha c1 manda cada evento em 3 tipos (omni_*, o
 * padrão e o offsite_conversion.fb_pixel_*), como a Meta faz com o pixel;
 * conta uma vez só (o omni_*). A c2 só tem o tipo do pixel.
 */
class MetaAdsFunilColunasTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $contasLidas = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_ads.access_token' => 'token-secreto',
            'services.meta_ads.ad_account_id' => 'act_111',
            'services.meta_ads.ferrakora_ad_account_id' => 'act_777',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
            'inertia.testing.ensure_pages_exist' => false,
        ]);

        $acao = fn (string $tipo, $valor) => ['action_type' => $tipo, 'value' => (string) $valor];
        $c1 = [
            'spend' => '100.00', 'impressions' => '10000', 'reach' => '8000', 'clicks' => '260',
            'inline_link_clicks' => '200', 'inline_link_click_ctr' => '2.0',
            'actions' => [
                $acao('link_click', 200),
                $acao('landing_page_view', 150), $acao('omni_landing_page_view', 150),
                $acao('add_to_cart', 20), $acao('omni_add_to_cart', 20), $acao('offsite_conversion.fb_pixel_add_to_cart', 20),
                $acao('initiate_checkout', 10), $acao('omni_initiated_checkout', 10), $acao('offsite_conversion.fb_pixel_initiate_checkout', 10),
                $acao('purchase', 4), $acao('omni_purchase', 4), $acao('offsite_conversion.fb_pixel_purchase', 4),
            ],
            'action_values' => [
                $acao('purchase', 400), $acao('omni_purchase', 400), $acao('offsite_conversion.fb_pixel_purchase', 400),
            ],
        ];
        $c2 = [
            'spend' => '50.00', 'impressions' => '5000', 'reach' => '4500', 'clicks' => '70',
            'inline_link_clicks' => '50', 'inline_link_click_ctr' => '1.0',
            'actions' => [
                $acao('landing_page_view', 40),
                $acao('offsite_conversion.fb_pixel_add_to_cart', 5),
                $acao('offsite_conversion.fb_pixel_initiate_checkout', 2),
                $acao('offsite_conversion.fb_pixel_purchase', 1),
            ],
            'action_values' => [$acao('offsite_conversion.fb_pixel_purchase', 150)],
        ];

        Http::fake(function (HttpRequest $request) use ($c1, $c2) {
            $url = $request->url();
            $nivel = $request->data()['level'] ?? null;
            if (preg_match('#/(act_\d+)/#', $url, $m)) {
                $this->contasLidas[] = $m[1];
            }

            return match (true) {
                str_contains($url, '/campaigns') => Http::response(['data' => [
                    ['id' => 'c1', 'name' => 'VENDAS 1', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '5000'],
                    ['id' => 'c2', 'name' => 'VENDAS 2', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '3000'],
                ]]),
                str_contains($url, '/adsets') => Http::response(['data' => [
                    ['id' => 's1', 'name' => 'Conjunto 1', 'campaign_id' => 'c1', 'effective_status' => 'ACTIVE'],
                    ['id' => 's2', 'name' => 'Conjunto 2', 'campaign_id' => 'c2', 'effective_status' => 'ACTIVE'],
                ]]),
                str_contains($url, '/ads') => Http::response(['data' => [
                    ['id' => 'a1', 'name' => 'AD01', 'adset_id' => 's1', 'campaign_id' => 'c1', 'effective_status' => 'ACTIVE'],
                    ['id' => 'a2', 'name' => 'AD02', 'adset_id' => 's2', 'campaign_id' => 'c2', 'effective_status' => 'ACTIVE'],
                ]]),
                str_contains($url, '/insights') && $nivel === 'account' => Http::response(['data' => [['spend' => '150.00', 'impressions' => '15000', 'inline_link_clicks' => '250']]]),
                str_contains($url, '/insights') => Http::response(['data' => [
                    [$nivel.'_id' => ['campaign' => 'c1', 'adset' => 's1', 'ad' => 'a1'][$nivel]] + $c1,
                    [$nivel.'_id' => ['campaign' => 'c2', 'adset' => 's2', 'ad' => 'a2'][$nivel]] + $c2,
                ]]),
                default => Http::response(['error' => ['message' => 'inesperado '.$url]], 400),
            };
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** @return array<string, mixed> o espelho que a tela recebe */
    private function espelho(string $url): array
    {
        $espelho = null;
        $this->actingAs($this->admin())->get($url)
            ->assertOk()
            // Closure comum (não arrow fn): a arrow fn copiaria $espelho em vez de usar a referência.
            ->assertInertia(function ($page) use (&$espelho) {
                $page->loadDeferredProps(function ($reload) use (&$espelho) {
                    $espelho = $reload->toArray()['props']['espelho'];
                });
            });

        $this->assertIsArray($espelho);
        $this->assertNull($espelho['erro']);

        return $espelho;
    }

    /** @param  array<string, mixed>  $esperado */
    private function confere(array $esperado, array $metricas, string $onde): void
    {
        foreach ($esperado as $chave => $valor) {
            $valor === null
                ? $this->assertNull($metricas[$chave], "$onde.$chave")
                : $this->assertEqualsWithDelta($valor, $metricas[$chave], 0.001, "$onde.$chave");
        }
    }

    public function test_funil_por_campanha_conta_cada_evento_uma_vez(): void
    {
        $campanhas = collect($this->espelho('/admin/publicidade/meta-ads/campanhas')['campanhas'])->keyBy('id');

        // c1: omni_* e o tipo do pixel vêm juntos; só o omni_* conta.
        $this->confere([
            'gasto' => 100, 'cliques_link' => 200, 'ctr_link' => 2.0, 'cpc_link' => 0.5,
            'visualizacoes_pagina' => 150, 'custo_pagina' => 0.67,
            'carrinho' => 20, 'custo_carrinho' => 5.0,
            'checkout' => 10, 'custo_checkout' => 10.0,
            'compras' => 4, 'custo_compra' => 25.0, 'roas' => 4.0, 'vendas' => 400,
        ], $campanhas['c1']['metricas'], 'c1');

        // c2: só offsite_conversion.fb_pixel_*, que vira o valor da coluna.
        $this->confere([
            'gasto' => 50, 'cliques_link' => 50, 'ctr_link' => 1.0, 'cpc_link' => 1.0,
            'visualizacoes_pagina' => 40, 'custo_pagina' => 1.25,
            'carrinho' => 5, 'custo_carrinho' => 10.0,
            'checkout' => 2, 'custo_checkout' => 25.0,
            'compras' => 1, 'custo_compra' => 50.0, 'roas' => 3.0, 'vendas' => 150,
        ], $campanhas['c2']['metricas'], 'c2');

        // Conjunto e anúncio no mesmo padrão.
        $this->confere(['custo_carrinho' => 5.0, 'custo_compra' => 25.0], $campanhas['c1']['conjuntos'][0]['metricas'], 's1');
        $this->confere(['custo_carrinho' => 5.0, 'custo_compra' => 25.0], $campanhas['c1']['conjuntos'][0]['anuncios'][0]['metricas'], 'a1');
    }

    public function test_total_do_periodo_recalcula_custos_e_roas_a_partir_das_somas(): void
    {
        $total = $this->espelho('/admin/publicidade/meta-ads/campanhas')['total'];

        // Soma: 150 de gasto, 250 cliques, 190 páginas, 25 carrinhos, 12 checkouts, 5 compras, R$ 550.
        // Média de médias daria ROAS 3,5 e CPC 0,75; o certo é 3,67 e 0,60.
        $this->confere([
            'gasto' => 150, 'impressoes' => 15000, 'alcance' => null,
            'cliques_link' => 250, 'ctr_link' => 1.67, 'cpc_link' => 0.6,
            'visualizacoes_pagina' => 190, 'custo_pagina' => 0.79,
            'carrinho' => 25, 'custo_carrinho' => 6.0,
            'checkout' => 12, 'custo_checkout' => 12.5,
            'compras' => 5, 'custo_compra' => 30.0, 'roas' => 3.67, 'vendas' => 550,
        ], $total, 'total');
    }

    public function test_ferrakora_tem_as_mesmas_colunas_lendo_a_conta_dela(): void
    {
        $espelho = $this->espelho('/admin/publicidade/meta-ads/campanhas?loja=ferrakora');

        $this->assertSame(['act_777'], array_values(array_unique($this->contasLidas)));
        $this->confere(['custo_checkout' => 12.5, 'roas' => 3.67], $espelho['total'], 'total FK');
        $this->assertArrayHasKey('custo_pagina', $espelho['campanhas'][0]['metricas']);
    }

    public function test_loja_sem_conta_configurada_mostra_aviso_em_vez_de_quebrar(): void
    {
        config(['services.meta_ads.ferrakora_ad_account_id' => null]);

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas?loja=ferrakora')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lojasMeta.loja', 'kazakora')
                ->where('lojasMeta.aviso', fn ($aviso) => str_contains((string) $aviso, 'FerraKora não tem conta de anúncios')));
    }

    public function test_sem_token_a_tela_abre_com_aviso_e_sem_total(): void
    {
        config(['services.meta_ads.access_token' => null]);

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('config.configurado', false)
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('espelho.campanhas', [])
                    ->where('espelho.total', null)));
    }
}

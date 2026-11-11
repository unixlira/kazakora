<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Support\Publicidade\CampanhasMetaPorLoja;
use App\Services\Meta\MetaAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Campanhas da Meta por loja (2026-11-11): KazaKora e FerraKora têm contas
 * de anúncios separadas, lidas com o mesmo token. Graph API falsa.
 */
class MetaAdsPainelLojasTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/publicidade/meta-ads';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inertia.testing.ensure_pages_exist' => false,
            'services.meta_ads.access_token' => 'token-secreto',
            'services.meta_ads.ad_account_id' => 'act_111',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
            'services.meta_ads.ferrakora_ad_account_id' => null,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * Linha de insights com o mesmo evento repetido em vários tipos de ação,
     * como a Meta manda (omni_*, padrão e offsite_conversion.fb_pixel_*).
     *
     * @return array<string, mixed>
     */
    private static function insights(float $gasto, int $carrinho, int $checkout, int $compras, float $valor, array $extra = []): array
    {
        $acoes = [];
        foreach (['add_to_cart' => $carrinho, 'initiate_checkout' => $checkout, 'purchase' => $compras] as $tipo => $qtd) {
            if ($qtd > 0) {
                $acoes[] = ['action_type' => 'offsite_conversion.fb_pixel_'.$tipo, 'value' => (string) $qtd];
                $acoes[] = ['action_type' => $tipo, 'value' => (string) $qtd];
            }
        }

        return $extra + [
            'spend' => (string) $gasto,
            'impressions' => '10000',
            'reach' => '6000',
            'clicks' => '300',
            'inline_link_clicks' => '200',
            'inline_link_click_ctr' => '2',
            'cpm' => (string) round($gasto / 10, 2),
            'actions' => $acoes,
            'action_values' => $compras > 0 ? [
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => (string) $valor],
                ['action_type' => 'purchase', 'value' => (string) $valor],
            ] : [],
        ];
    }

    /**
     * Conta falsa: uma campanha ativa com 2 anúncios e uma pausada sem gasto.
     *
     * @return array<string, mixed>
     */
    private static function conta(string $prefixo, float $gasto, int $compras, float $valor): array
    {
        return [
            'campaigns' => [
                ['id' => $prefixo.'1', 'name' => "Campanha {$prefixo}", 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '5000'],
                ['id' => $prefixo.'9', 'name' => "Velha {$prefixo}", 'effective_status' => 'PAUSED', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '2000'],
            ],
            'adsets' => [
                ['id' => $prefixo.'2', 'name' => 'Conjunto', 'campaign_id' => $prefixo.'1', 'effective_status' => 'ACTIVE'],
            ],
            'ads' => [
                ['id' => $prefixo.'3', 'name' => 'p1', 'adset_id' => $prefixo.'2', 'campaign_id' => $prefixo.'1', 'effective_status' => 'ACTIVE'],
                ['id' => $prefixo.'4', 'name' => 'p2', 'adset_id' => $prefixo.'2', 'campaign_id' => $prefixo.'1', 'effective_status' => 'ACTIVE'],
            ],
            'insights' => [
                'account' => [self::insights($gasto, 5, 3, $compras, $valor)],
                'campaign' => [self::insights($gasto, 5, 3, $compras, $valor, ['campaign_id' => $prefixo.'1'])],
                'adset' => [self::insights($gasto, 5, 3, $compras, $valor, ['adset_id' => $prefixo.'2'])],
                'ad' => [
                    self::insights($gasto - 10, 4, 2, $compras, $valor, ['ad_id' => $prefixo.'3']),
                    self::insights(10, 1, 1, 0, 0, ['ad_id' => $prefixo.'4']),
                ],
            ],
        ];
    }

    /** @param  array<string, array<string, mixed>|string>  $contas  act_ => dados, ou 'limite' para responder com erro 17 */
    private function fingirMeta(array $contas): void
    {
        Http::fake(function (HttpRequest $request) use ($contas) {
            foreach ($contas as $act => $dados) {
                if (! str_contains($request->url(), '/'.$act.'/')) {
                    continue;
                }
                if ($dados === 'limite') {
                    return Http::response(['error' => ['message' => 'User request limit reached', 'code' => 17]], 400);
                }
                foreach (['campaigns', 'adsets', 'ads'] as $borda) {
                    if (str_contains($request->url(), '/'.$act.'/'.$borda.'?')) {
                        return Http::response(['data' => $dados[$borda]]);
                    }
                }
                if (str_contains($request->url(), '/'.$act.'/insights')) {
                    return Http::response(['data' => $dados['insights'][$request['level']] ?? []]);
                }
            }

            return Http::response(['data' => []]);
        });
    }

    public function test_sem_conta_da_ferrakora_mostra_so_a_kazakora(): void
    {
        $this->fingirMeta(['act_111' => self::conta('11', 100, 2, 300)]);

        $this->actingAs($this->admin())->get(self::URL.'?loja=ferrakora')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lojasMeta.lojas', ['kazakora' => 'KazaKora'])
                ->where('lojasMeta.loja', 'kazakora')
                ->where('lojasMeta.periodo', 'last_7d')
                ->missing('campanhasLojas')
                ->loadDeferredProps(fn ($recarga) => $recarga
                    ->has('campanhasLojas', 1)
                    ->where('campanhasLojas.0.loja', 'kazakora')
                    ->where('campanhasLojas.0.erro', null)));
    }

    public function test_ambas_mostra_as_duas_contas_sem_contar_evento_em_dobro(): void
    {
        config(['services.meta_ads.ferrakora_ad_account_id' => 'act_222']);
        $this->fingirMeta([
            'act_111' => self::conta('11', 100, 2, 300),
            'act_222' => self::conta('22', 80, 4, 1000),
        ]);

        $this->actingAs($this->admin())->get(self::URL.'?loja=ambas&periodo_lojas=yesterday')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lojasMeta.lojas', ['kazakora' => 'KazaKora', 'ferrakora' => 'FerraKora'])
                ->where('lojasMeta.loja', 'ambas')
                ->where('lojasMeta.periodo', 'yesterday')
                ->loadDeferredProps(fn ($recarga) => $recarga
                    ->has('campanhasLojas', 2)
                    // KazaKora: totais da conta.
                    ->where('campanhasLojas.0.nome', 'KazaKora')
                    ->where('campanhasLojas.0.totais.gasto', 100)
                    ->where('campanhasLojas.0.totais.carrinho', 5)
                    ->where('campanhasLojas.0.totais.checkout', 3)
                    ->where('campanhasLojas.0.totais.compras', 2)
                    ->where('campanhasLojas.0.totais.vendas', 300)
                    ->where('campanhasLojas.0.totais.roas', 3)
                    ->where('campanhasLojas.0.totais.custo_compra', 50)
                    ->where('campanhasLojas.0.totais.alcance', 6000)
                    ->where('campanhasLojas.0.totais.cliques_link', 200)
                    ->where('campanhasLojas.0.totais.cpc_link', 0.5)
                    ->where('campanhasLojas.0.totais.cpm', 10)
                    // A pausada sem gasto fica fora e é contada.
                    ->has('campanhasLojas.0.campanhas', 1)
                    ->where('campanhasLojas.0.ocultas', 1)
                    // FerraKora: a campanha com os anúncios, mais gasto primeiro.
                    ->where('campanhasLojas.1.loja', 'ferrakora')
                    ->where('campanhasLojas.1.conta', 'act_222')
                    ->where('campanhasLojas.1.campanhas.0.nome', 'Campanha 22')
                    ->where('campanhasLojas.1.campanhas.0.status', 'ACTIVE')
                    ->where('campanhasLojas.1.campanhas.0.orcamento_diario', 50)
                    ->where('campanhasLojas.1.campanhas.0.compras', 4)
                    ->where('campanhasLojas.1.campanhas.0.vendas', 1000)
                    ->where('campanhasLojas.1.campanhas.0.custo_compra', 20)
                    ->where('campanhasLojas.1.campanhas.0.ctr_link', 2)
                    ->where('campanhasLojas.1.campanhas.0.gerenciador', fn ($link) => str_contains($link, 'act=222'))
                    ->has('campanhasLojas.1.campanhas.0.anuncios', 2)
                    ->where('campanhasLojas.1.campanhas.0.anuncios.0.nome', 'p1')
                    ->where('campanhasLojas.1.campanhas.0.anuncios.0.gasto', 70)
                    ->where('campanhasLojas.1.campanhas.0.anuncios.1.nome', 'p2')
                    ->where('campanhasLojas.1.campanhas.0.anuncios.1.carrinho', 1)
                    ->where('campanhasLojas.1.campanhas.0.anuncios.1.custo_compra', null)));

        // Cada conta foi lida com o período pedido e com o mesmo token.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'act_222/insights') && $r['date_preset'] === 'yesterday'
            && $r->hasHeader('Authorization', 'Bearer token-secreto'));
    }

    public function test_limite_de_chamadas_vira_aviso_e_nao_insiste_na_meta(): void
    {
        config(['services.meta_ads.ferrakora_ad_account_id' => 'act_222']);
        $this->fingirMeta([
            'act_111' => self::conta('11', 100, 2, 300),
            'act_222' => 'limite',
        ]);
        $painel = app(CampanhasMetaPorLoja::class);

        $lojas = $painel->montar('ambas', 'today');

        $this->assertNull($lojas[0]['erro']);
        $this->assertSame(100.0, $lojas[0]['totais']['gasto']);
        $this->assertStringContainsString('limitou as consultas', (string) $lojas[1]['erro']);
        $this->assertStringContainsString('(código 17)', (string) $lojas[1]['erro']);
        $this->assertSame([], $lojas[1]['campanhas']);

        // Segunda leitura: a KazaKora vem do cache e a FerraKora fica em pausa, sem chamada nova.
        $chamadas = count(Http::recorded());
        $denovo = $painel->montar('ambas', 'today');
        $this->assertCount($chamadas, Http::recorded());
        $this->assertStringContainsString('limitou as consultas', (string) $denovo[1]['erro']);
        $this->assertSame(100.0, $denovo[0]['totais']['gasto']);
    }

    public function test_na_conta_nao_muda_a_conta_do_servico_original(): void
    {
        $this->fingirMeta([
            'act_111' => self::conta('11', 100, 2, 300),
            'act_222' => self::conta('22', 80, 4, 1000),
        ]);
        $meta = app(MetaAdsService::class);

        $this->assertSame(80.0, $meta->naConta('act_222')->resumoDaConta('today')['gasto']);
        $this->assertSame(100.0, $meta->resumoDaConta('today')['gasto']);
        $this->assertStringContainsString('act=111', (string) $meta->linkCampanhaGerenciador('1'));
        $this->assertStringContainsString('act=222', (string) $meta->naConta('222')->linkCampanhaGerenciador('1'));
    }

    public function test_periodo_e_loja_invalidos_caem_no_padrao(): void
    {
        $this->assertSame('last_7d', CampanhasMetaPorLoja::periodo('maximum'));
        $this->assertSame('kazakora', app(CampanhasMetaPorLoja::class)->loja('ambas'));
    }
}

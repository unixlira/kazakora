<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Metadados das campanhas da Meta (pedido do Lira em 2026-10-27): a rotina
 * grava estrutura e performance diária por anúncio no banco; a tela lista
 * as campanhas e, dentro da campanha, conjuntos, anúncios e o dia a dia.
 */
class MetaAdsDadosTest extends TestCase
{
    use RefreshDatabase;

    private array $naMeta;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_ads.access_token' => 'token-teste',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '456',
        ]);

        $this->naMeta = [
            'campaigns' => [['id' => '100', 'name' => 'Caixa 85', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES',
                'daily_budget' => '3000', 'start_time' => '2026-10-20T10:00:00-0300', 'created_time' => '2026-10-20T09:00:00-0300']],
            'adsets' => [['id' => '200', 'name' => 'Brasil 25-55', 'campaign_id' => '100', 'effective_status' => 'ACTIVE']],
            'ads' => [['id' => '300', 'name' => 'Vídeo 1', 'adset_id' => '200', 'campaign_id' => '100', 'effective_status' => 'ACTIVE',
                'creative' => ['thumbnail_url' => 'https://exemplo.test/m.jpg']]],
            'insights' => [
                $this->linha('100', 'Caixa 85', '200', 'Brasil 25-55', '300', 'Vídeo 1', '2026-10-25', '10.50', '1000', '20', '1', '89.90'),
                $this->linha('100', 'Caixa 85', '200', 'Brasil 25-55', '300', 'Vídeo 1', '2026-10-26', '9.50', '800', '10', '0', '0'),
                // Anúncio excluído: só vem pelo insights.
                $this->linha('900', 'Árvore de Natal', '901', 'Conjunto antigo', '902', 'Criativo antigo', '2026-10-01', '5.00', '300', '3', '0', '0'),
            ],
        ];

        Http::fake(function (Request $request) {
            $borda = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['data' => $this->naMeta[$borda] ?? []]);
        });
    }

    private function linha(string $c, string $cn, string $s, string $sn, string $a, string $an, string $dia, string $gasto, string $imp, string $link, string $compras, string $vendas): array
    {
        return ['campaign_id' => $c, 'campaign_name' => $cn, 'adset_id' => $s, 'adset_name' => $sn, 'ad_id' => $a, 'ad_name' => $an,
            'date_start' => $dia, 'date_stop' => $dia, 'spend' => $gasto, 'impressions' => $imp, 'clicks' => $link, 'inline_link_clicks' => $link,
            'actions' => [['action_type' => 'omni_purchase', 'value' => $compras]],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => $vendas]]];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_rotina_grava_estrutura_desempenho_e_excluidos_sem_duplicar(): void
    {
        $this->artisan('meta-ads:gravar-dados', ['--tudo' => true])->assertSuccessful();
        $this->artisan('meta-ads:gravar-dados', ['--tudo' => true])->assertSuccessful();

        $this->assertSame(2, DB::table('meta_ads_dados_campanhas')->count());
        $this->assertDatabaseHas('meta_ads_dados_campanhas', ['meta_id' => '100', 'status' => 'ACTIVE', 'orcamento_diario' => 30.00, 'inicio' => '2026-10-20']);
        $this->assertDatabaseHas('meta_ads_dados_campanhas', ['meta_id' => '900', 'nome' => 'Árvore de Natal', 'status' => 'DELETED']);
        $this->assertDatabaseHas('meta_ads_dados_anuncios', ['meta_id' => '902', 'conjunto_meta_id' => '901', 'status' => 'DELETED']);
        $this->assertSame(3, DB::table('meta_ads_dados_desempenho')->count());
        $this->assertDatabaseHas('meta_ads_dados_desempenho', ['anuncio_meta_id' => '300', 'data' => '2026-10-25', 'gasto' => 10.50, 'compras' => 1, 'vendas' => 89.90]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'act_123/insights') && str_contains(urldecode($r->url()), 'DELETED')
            && str_contains($r->url(), 'time_increment=1'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_campanha_que_sumiu_da_lista_vira_excluida(): void
    {
        $this->artisan('meta-ads:gravar-dados')->assertSuccessful();
        $this->naMeta['campaigns'] = [];

        $this->artisan('meta-ads:gravar-dados')->assertSuccessful();

        $this->assertDatabaseHas('meta_ads_dados_campanhas', ['meta_id' => '100', 'status' => 'DELETED']);
    }

    public function test_tabela_de_campanhas_e_pagina_da_campanha(): void
    {
        $this->artisan('meta-ads:gravar-dados', ['--tudo' => true])->assertSuccessful();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/publicidade/meta-ads/metadados')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MetaAds/Dados/Index', false)
                ->has('campanhas', 2)
                ->where('campanhas.0.meta_id', '100')
                ->where('campanhas.0.conjuntos', 1)
                ->where('campanhas.0.anuncios', 1)
                ->where('campanhas.0.metricas.gasto', 20)
                ->where('campanhas.0.metricas.cliques_link', 30)
                ->where('campanhas.0.metricas.compras', 1)
                ->where('campanhas.0.metricas.roas', 4.5)
                ->where('totais.gasto', 25));

        $this->actingAs($admin)->get('/admin/publicidade/meta-ads/metadados/100')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MetaAds/Dados/Campanha', false)
                ->where('campanha.nome', 'Caixa 85')
                ->has('conjuntos', 1)
                ->where('conjuntos.0.anuncios', 1)
                ->has('anuncios', 1)
                ->where('anuncios.0.conjunto', 'Brasil 25-55')
                ->where('anuncios.0.metricas.gasto', 20)
                ->has('diario.300', 2)
                ->where('diario.300.0.data', '2026-10-26'));

        $this->actingAs($admin)->get('/admin/publicidade/meta-ads/metadados/555')->assertNotFound();
    }

    public function test_sem_permissao_nao_ve(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->get('/admin/publicidade/meta-ads/metadados')
            ->assertStatus(403);
    }
}

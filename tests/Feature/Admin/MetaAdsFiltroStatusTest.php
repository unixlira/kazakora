<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Filtro de status do "Na Meta agora" (pedido do Lira 2026-10-21): abre em
 * "Ativos" e troca para pausados, arquivados, excluídos etc. O status vem
 * da Meta (effective_status). Arquivado só vem pedindo o effective_status;
 * excluído a Meta não lista, sai do insights e é lido pelo id.
 */
class MetaAdsFiltroStatusTest extends TestCase
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
            'inertia.testing.ensure_pages_exist' => false,
        ]);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $dados = $request->data();
            $nivel = $dados['level'] ?? null;
            $comArquivados = isset($dados['effective_status']);
            $comExcluidos = str_contains((string) ($dados['filtering'] ?? ''), 'DELETED');

            if (str_contains((string) ($dados['effective_status'] ?? ''), 'DELETED')) {
                return Http::response(['error' => ['message' => 'objetos excluídos', 'code' => 100, 'error_subcode' => 1815001]], 400);
            }

            $metricas = fn (string $id) => [$nivel.'_id' => $id, 'spend' => '10.00', 'impressions' => '1000', 'reach' => '800', 'clicks' => '20'];

            return match (true) {
                str_contains($url, '/act_123/campaigns') => Http::response(['data' => array_merge([
                    ['id' => 'c1', 'name' => 'Ativa', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '3000'],
                    ['id' => 'c2', 'name' => 'Pausada', 'effective_status' => 'PAUSED', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '3000'],
                ], $comArquivados ? [['id' => 'c3', 'name' => 'Arquivada', 'effective_status' => 'ARCHIVED', 'objective' => 'OUTCOME_SALES']] : [])]),
                str_contains($url, '/act_123/adsets') => Http::response(['data' => array_merge([
                    ['id' => 's1', 'name' => 'Aberto', 'campaign_id' => 'c1', 'effective_status' => 'ACTIVE'],
                    ['id' => 's2', 'name' => 'Da pausada', 'campaign_id' => 'c2', 'effective_status' => 'CAMPAIGN_PAUSED'],
                ], $comArquivados ? [['id' => 's3', 'name' => 'Da arquivada', 'campaign_id' => 'c3', 'effective_status' => 'ARCHIVED']] : [])]),
                str_contains($url, '/act_123/ads') => Http::response(['data' => array_merge([
                    ['id' => 'a1', 'name' => 'Vídeo ativo', 'adset_id' => 's1', 'campaign_id' => 'c1', 'effective_status' => 'ACTIVE'],
                    ['id' => 'a2', 'name' => 'Vídeo pausado', 'adset_id' => 's1', 'campaign_id' => 'c1', 'effective_status' => 'PAUSED'],
                    ['id' => 'a3', 'name' => 'Vídeo da pausada', 'adset_id' => 's2', 'campaign_id' => 'c2', 'effective_status' => 'CAMPAIGN_PAUSED'],
                ], $comArquivados ? [['id' => 'a5', 'name' => 'Vídeo arquivado', 'adset_id' => 's3', 'campaign_id' => 'c3', 'effective_status' => 'ARCHIVED']] : [])]),
                str_contains($url, '/act_123/insights') && $nivel === 'account' => Http::response(['data' => [['spend' => '30.00', 'impressions' => '3000', 'inline_link_clicks' => '60']]]),
                str_contains($url, '/act_123/insights') => Http::response(['data' => array_merge(
                    array_map($metricas, ['campaign' => ['c1', 'c2'], 'adset' => ['s1', 's2'], 'ad' => ['a1', 'a2', 'a3']][$nivel]),
                    $comExcluidos && $nivel === 'ad' ? [$metricas('a4')] : [],
                )]),
                str_contains($url, '/v23.0/a4') => Http::response(['id' => 'a4', 'name' => 'Vídeo excluído', 'adset_id' => 's1', 'campaign_id' => 'c1', 'effective_status' => 'DELETED']),
                default => Http::response(['error' => ['message' => 'inesperado '.$url]], 400),
            };
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_abre_so_com_os_ativos(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status', 'ativos')
                ->where('statusFiltros.ativos', 'Ativos')
                ->where('statusFiltros.excluidos', 'Excluídos')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('espelho.erro', null)
                    ->has('espelho.campanhas', 1)
                    ->where('espelho.campanhas.0.id', 'c1')
                    ->has('espelho.campanhas.0.conjuntos.0.anuncios', 1)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.id', 'a1')
                    // A confirmação de excluir conta tudo o que está dentro.
                    ->where('espelho.campanhas.0.anuncios_total', 2)));

        // Ativos não pede arquivados à Meta.
        Http::assertNotSent(fn (HttpRequest $r) => isset($r->data()['effective_status']));
    }

    public function test_pausados_mostra_o_caminho_ate_o_anuncio_pausado(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas?status=pausados')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status', 'pausados')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->has('espelho.campanhas', 2)
                    ->where('espelho.campanhas.0.id', 'c1')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.id', 'a2')
                    ->has('espelho.campanhas.0.conjuntos.0.anuncios', 1)
                    ->where('espelho.campanhas.1.id', 'c2')
                    ->where('espelho.campanhas.1.conjuntos.0.anuncios.0.id', 'a3')));
    }

    public function test_arquivados_vem_pedindo_o_status_a_meta(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas?status=arquivados')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->loadDeferredProps(fn ($reload) => $reload
                    ->has('espelho.campanhas', 1)
                    ->where('espelho.campanhas.0.id', 'c3')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.id', 'a5')));
    }

    public function test_excluidos_sai_do_insights_e_e_lido_pelo_id(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas?status=excluidos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status', 'excluidos')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('espelho.erro', null)
                    ->has('espelho.campanhas', 1)
                    ->where('espelho.campanhas.0.id', 'c1')
                    ->has('espelho.campanhas.0.conjuntos.0.anuncios', 1)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.id', 'a4')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.status', 'DELETED')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.metricas.gasto', 10)));
    }

    public function test_todos_e_status_invalido(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/publicidade/meta-ads/campanhas?status=todos')
            ->assertInertia(fn ($page) => $page
                ->loadDeferredProps(fn ($reload) => $reload->has('espelho.campanhas', 3)));

        $this->actingAs($admin)->get('/admin/publicidade/meta-ads/campanhas?status=qualquer')
            ->assertInertia(fn ($page) => $page->where('status', 'ativos'));
    }
}

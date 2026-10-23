<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Central de campanhas (2026-10-23): Meta (Graph API falsa) e Google (sem
 * integração) numa lista só, só leitura.
 */
class PublicidadeCampanhasTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/publicidade/campanhas';

    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.testing.ensure_pages_exist' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function configurarMeta(): void
    {
        config([
            'services.meta_ads.access_token' => 'token-secreto',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
        ]);
    }

    private function fingirMeta(): void
    {
        $compra = fn (int $qtd, float $valor) => [
            'actions' => [['action_type' => 'omni_purchase', 'value' => (string) $qtd]],
            'action_values' => [['action_type' => 'omni_purchase', 'value' => (string) $valor]],
        ];

        Http::fake(function (HttpRequest $request) use ($compra) {
            $url = $request->url();
            $nivel = $request->data()['level'] ?? null;

            return match (true) {
                str_contains($url, '/act_123/campaigns') => Http::response(['data' => [
                    ['id' => 'c1', 'name' => 'Vendas | Árvore de Natal', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '3000'],
                    ['id' => 'c2', 'name' => 'Cozedor de ovo', 'effective_status' => 'PAUSED', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '1000'],
                    ['id' => 'c3', 'name' => 'Luminária reprovada', 'effective_status' => 'WITH_ISSUES', 'objective' => 'OUTCOME_SALES'],
                    ['id' => 'c4', 'name' => 'Organizador sem venda', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '2000'],
                    ['id' => 'c5', 'name' => 'Arquivada antiga', 'effective_status' => 'ARCHIVED', 'objective' => 'OUTCOME_SALES'],
                    ['id' => 'c6', 'name' => 'Dia das Crianças', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'stop_time' => '2026-10-13T23:59:00-0300'],
                    ['id' => 'c7', 'name' => 'Tráfego para o site', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_TRAFFIC', 'daily_budget' => '1500'],
                ]]),
                str_contains($url, '/act_123/adsets'), str_contains($url, '/act_123/ads') => Http::response(['data' => []]),
                str_contains($url, '/act_123/insights') && $nivel === 'campaign' => Http::response(['data' => [
                    ['campaign_id' => 'c1', 'spend' => '40.00', 'impressions' => '2000', 'clicks' => '80'] + $compra(2, 200),
                    ['campaign_id' => 'c4', 'spend' => '50.00', 'impressions' => '3000', 'clicks' => '60'],
                    ['campaign_id' => 'c6', 'spend' => '5.00', 'impressions' => '100', 'clicks' => '2'],
                    ['campaign_id' => 'c7', 'spend' => '45.00', 'impressions' => '4000', 'clicks' => '150'],
                ]]),
                str_contains($url, '/act_123/insights') => Http::response(['data' => []]),
                default => Http::response(['error' => ['message' => 'inesperado']], 400),
            };
        });
    }

    public function test_lista_campanhas_da_meta_com_status_numeros_e_alertas(): void
    {
        $this->configurarMeta();
        $this->fingirMeta();
        $local = MetaAdsCampanha::create([
            'nome' => 'Árvore', 'objetivo' => 'OUTCOME_SALES', 'orcamento_diario' => 30,
            'inicio' => now()->toDateString(), 'idade_min' => 25, 'idade_max' => 55, 'genero' => 'todos',
            'link_destino' => 'https://kazakora.com/produtos/arvore', 'chamada' => 'SHOP_NOW',
            'status' => MetaAdsCampanha::STATUS_ATIVA, 'meta_campaign_id' => 'c1',
        ]);

        $this->actingAs($this->admin())->get(self::URL.'?periodo=30d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Campanhas', false)
                ->where('periodo', '30d')
                ->where('plataformas.0.canal', 'meta_ads')
                ->where('plataformas.0.conectada', true)
                ->where('plataformas.1.canal', 'google_ads')
                ->where('plataformas.1.conectada', false)
                ->missing('central')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->has('central.campanhas', 6)
                    ->where('central.ocultas', 1)
                    ->where('central.porPlataforma.meta_ads.erro', null)
                    ->where('central.porPlataforma.meta_ads.totais.gasto', 140)
                    ->where('central.porPlataforma.meta_ads.totais.cliques', 292)
                    ->where('central.porPlataforma.meta_ads.totais.resultados', 2)
                    ->where('central.porPlataforma.meta_ads.totais.roas', 1.43)
                    ->where('central.porPlataforma.meta_ads.totais.ativas', 3)
                    ->where('central.porPlataforma.google_ads.totais.campanhas', 0)
                    // A Meta ordena: ativas pelo maior gasto, depois as outras.
                    ->where('central.campanhas.0.nome', 'Organizador sem venda')
                    ->where('central.campanhas.1.nome', 'Tráfego para o site')
                    ->where('central.campanhas.1.busca_venda', false)
                    ->where('central.campanhas.2.nome', 'Vendas | Árvore de Natal')
                    ->where('central.campanhas.2.plataforma', 'meta_ads')
                    ->where('central.campanhas.2.status', 'ativa')
                    ->where('central.campanhas.2.orcamento_diario', 30)
                    ->where('central.campanhas.2.ctr', 4)
                    ->where('central.campanhas.2.cpc', 0.5)
                    ->where('central.campanhas.2.resultados', 2)
                    ->where('central.campanhas.2.custo_por_resultado', 20)
                    ->where('central.campanhas.2.roas', 5)
                    ->where('central.campanhas.2.detalhe', "/admin/publicidade/meta-ads/campanhas/{$local->id}?periodo=last_30d")
                    ->where('central.campanhas.2.gerenciador', fn ($link) => str_contains((string) $link, 'c1'))
                    ->where('central.campanhas.3.nome', 'Dia das Crianças')
                    ->where('central.campanhas.3.status', 'encerrada')
                    ->where('central.campanhas.4.status', 'pausada')
                    ->where('central.campanhas.4.detalhe', '/admin/publicidade/meta-ads/campanhas?periodo=last_30d&status=todos')
                    ->where('central.campanhas.5.status', 'com_problema')
                    ->where('central.campanhas.5.status_plataforma', 'Com problema')
                    // Problema primeiro; tráfego (c7) não é cobrado por não vender.
                    ->has('central.alertas', 2)
                    ->where('central.alertas.0.titulo', 'Campanha com problema na plataforma')
                    ->where('central.alertas.0.alvo', 'Luminária reprovada')
                    ->where('central.alertas.1.titulo', 'Gastou R$ 50,00 sem venda nos últimos 30 dias')
                    ->where('central.alertas.1.alvo', 'Organizador sem venda')));

        // Só leitura: nenhuma chamada que escreve na Meta.
        Http::assertSent(fn (HttpRequest $r) => ($r->data()['date_preset'] ?? null) === 'last_30d');
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET');
    }

    public function test_mes_atual_pede_this_month_para_a_meta(): void
    {
        $this->configurarMeta();
        $this->fingirMeta();

        $this->actingAs($this->admin())->get(self::URL.'?periodo=mes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('periodo', 'mes')
                ->loadDeferredProps(fn ($reload) => $reload->has('central.campanhas', 6)));

        Http::assertSent(fn (HttpRequest $r) => ($r->data()['date_preset'] ?? null) === 'this_month');
    }

    public function test_erro_da_meta_vira_texto_e_nao_derruba_a_tela(): void
    {
        $this->configurarMeta();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Token expirado', 'code' => 190]], 400)]);

        $this->actingAs($this->admin())->get(self::URL)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('periodo', '7d')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('central.campanhas', [])
                    ->where('central.alertas', [])
                    ->where('central.porPlataforma.meta_ads.erro', 'Token expirado (código 190)')));
    }

    public function test_sem_integracao_mostra_o_que_falta_e_nao_chama_api(): void
    {
        config(['services.meta_ads.access_token' => null]);
        Http::fake();

        $this->actingAs($this->admin())->get(self::URL.'?periodo=invalido')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('periodo', '7d')
                ->where('plataformas.0.conectada', false)
                ->where('plataformas.1.conectada', false)
                ->where('plataformas.1.nome', 'Google ADS')
                ->has('plataformas.1.precisa', 3)
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('central.campanhas', [])
                    ->where('central.totais.gasto', 0)));

        Http::assertNothingSent();
    }

    public function test_pede_login(): void
    {
        $this->get(self::URL)->assertRedirect();
    }
}

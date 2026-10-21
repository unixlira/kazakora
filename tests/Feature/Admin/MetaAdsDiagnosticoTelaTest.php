<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Coluna "Botão (CTA)" e "O que fazer agora" (2026-10-21): o espelho traz o
 * botão de cada anúncio e o diagnóstico; a tela resumo traz a versão curta.
 * Se a Meta recusar os campos novos, a tela continua com os básicos.
 */
class MetaAdsDiagnosticoTelaTest extends TestCase
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
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function fakeMeta(bool $recusarExtras = false): void
    {
        Http::fake(function (HttpRequest $request) use ($recusarExtras) {
            $url = $request->url();
            $dados = $request->data();
            $nivel = $dados['level'] ?? null;
            $campos = (string) ($dados['fields'] ?? '');

            if ($recusarExtras && (str_contains($campos, 'call_to_action_type') || str_contains($campos, 'learning_stage_info') || str_contains($campos, 'inline_link_clicks'))) {
                return Http::response(['error' => ['message' => 'campo inválido', 'code' => 100]], 400);
            }

            return match (true) {
                str_contains($url, '/act_123/campaigns') => Http::response(['data' => [
                    ['id' => 'c1', 'name' => 'Vendas Caixa', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '3000'],
                ]]),
                str_contains($url, '/act_123/adsets') => Http::response(['data' => [
                    ['id' => 's1', 'name' => 'Aberto', 'campaign_id' => 'c1', 'effective_status' => 'ACTIVE'],
                ]]),
                str_contains($url, '/act_123/ads') => Http::response(['data' => [
                    ['id' => 'a1', 'name' => 'Vídeo 1', 'adset_id' => 's1', 'campaign_id' => 'c1', 'effective_status' => 'ACTIVE', 'created_time' => '2026-10-01T10:00:00-0300',
                        'creative' => ['thumbnail_url' => 'https://x/t.jpg', 'call_to_action_type' => 'LEARN_MORE']],
                ]]),
                str_contains($url, '/act_123/insights') && $nivel === 'account' => Http::response(['data' => [
                    ['spend' => '80.00', 'impressions' => '5000', 'inline_link_clicks' => '100'],
                ]]),
                str_contains($url, '/act_123/insights') => Http::response(['data' => [
                    [$nivel.'_id' => ['campaign' => 'c1', 'adset' => 's1', 'ad' => 'a1'][$nivel], 'spend' => '80.00', 'impressions' => '5000', 'reach' => '3000', 'clicks' => '120',
                        'inline_link_clicks' => '100', 'inline_link_click_ctr' => '2.0', 'frequency' => '1.6',
                        'actions' => [['action_type' => 'landing_page_view', 'value' => '30']]],
                ]]),
                default => Http::response(['error' => ['message' => 'inesperado '.$url]], 400),
            };
        });
    }

    public function test_espelho_traz_o_botao_e_o_diagnostico(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas?periodo=last_7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('espelho.erro', null)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.cta.codigos', ['LEARN_MORE'])
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.cta.rotulos.LEARN_MORE', 'Saiba mais')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.cta.desalinhado', true)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.metricas.cliques_link', 100)
                    ->where('espelho.diagnostico.estado', 'ok')
                    // Sem vendas no site: ROAS mínimo do valor da configuração.
                    ->where('espelho.diagnostico.roas_minimo.origem', 'config')
                    ->where('espelho.diagnostico.roas_minimo.valor', 3)
                    // R1 (gastou e não vendeu), R2 (clicaram e não compraram) e R5 (botão).
                    ->where('espelho.diagnostico.cartoes.0.regra', 'R1')
                    ->where('espelho.diagnostico.cartoes.1.regra', 'R2')
                    ->where('espelho.diagnostico.cartoes.1.acao', 'A página demora para abrir. Confira a velocidade da página do produto no celular.')
                    ->where('espelho.diagnostico.total', 3)
                    ->where('espelho.diagnostico.faltando', [])));
    }

    public function test_meta_recusando_os_campos_novos_a_tela_continua(): void
    {
        $this->fakeMeta(recusarExtras: true);

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('espelho.erro', null)
                    ->has('espelho.campanhas.0.conjuntos.0.anuncios', 1)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.cta', null)
                    ->where('espelho.diagnostico.faltando', ['aprendizado', 'botao', 'insights_extras', 'media_da_conta'])));
    }

    public function test_tela_resumo_traz_a_versao_curta(): void
    {
        $this->fakeMeta();

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Plataforma', false)
                ->missing('diagnostico')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('diagnostico.erro', null)
                    ->where('diagnostico.periodo', 'last_7d')
                    ->where('diagnostico.cartoes.0.regra', 'R1')));
    }
}

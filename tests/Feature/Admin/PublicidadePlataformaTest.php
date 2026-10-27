<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menu Publicidade, seções Meta ADS e Google ADS (2026-10-12): sem
 * integração, a tela mostra "não conectada" e zero; com gasto
 * sincronizado em channel_ad_spends, mostra os números da plataforma.
 */
class PublicidadePlataformaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_meta_ads_sem_integracao_mostra_nao_conectada(): void
    {
        // Gasto de outro canal não pode aparecer na tela da Meta.
        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'shopee', 'spend' => 50, 'attributed_gmv' => 200]);

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Plataforma', false)
                ->where('plataforma.canal', 'meta_ads')
                ->where('plataforma.nome', 'Meta ADS')
                ->where('conectado', false)
                ->where('ultimaSincronizacao', null)
                ->where('periodos.0.totais.gasto', 0)
                ->where('periodos.0.totais.roas', null));
    }

    public function test_meta_ads_com_api_configurada_mostra_conectada_sem_gasto_gravado(): void
    {
        config([
            'services.meta_ads.access_token' => 'token-teste',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '456',
        ]);

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Plataforma', false)
                ->where('conectado', true)
                ->where('ultimaSincronizacao', null)
                ->where('temDiagnostico', true));
    }

    public function test_meta_ads_sem_gasto_gravado_le_gasto_e_resultados_da_meta(): void
    {
        config([
            'services.meta_ads.access_token' => 'token-teste',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '456',
            'inertia.testing.ensure_pages_exist' => false,
        ]);
        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'act_123/insights') || ! str_contains($request->url(), 'level=account')) {
                return Http::response(['data' => []]);
            }
            $porPeriodo = [
                'this_month' => ['spend' => '743.73', 'impressions' => '14497', 'clicks' => '722',
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '2']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '330.52']]],
                'last_month' => null,
                'maximum' => ['spend' => '1302.82', 'impressions' => '24740', 'clicks' => '1787',
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '3']],
                    'action_values' => [['action_type' => 'omni_purchase', 'value' => '476.24']]],
            ];
            $linha = $porPeriodo[$request['date_preset']] ?? null;

            return Http::response(['data' => $linha ? [$linha] : []]);
        });

        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Plataforma', false)
                ->where('daMeta', true)
                ->missing('resumoMeta')
                ->loadDeferredProps(fn ($recarga) => $recarga
                    ->where('resumoMeta.erro', null)
                    ->where('resumoMeta.periodos.0.titulo', 'Mês atual')
                    ->where('resumoMeta.periodos.0.totais.gasto', 743.73)
                    ->where('resumoMeta.periodos.0.totais.pedidos', 2)
                    ->where('resumoMeta.periodos.0.totais.vendas', 330.52)
                    ->where('resumoMeta.periodos.1.totais.gasto', 0)
                    ->where('resumoMeta.periodos.2.titulo', 'Desde o início')
                    ->where('resumoMeta.periodos.2.totais.gasto', 1302.82)
                    ->where('resumoMeta.periodos.2.totais.cliques', 1787)));
    }

    public function test_google_ads_sem_integracao_mostra_nao_conectada(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/google-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Plataforma', false)
                ->where('plataforma.canal', 'google_ads')
                ->where('plataforma.nome', 'Google ADS')
                ->where('conectado', false));
    }

    public function test_com_gasto_sincronizado_mostra_os_numeros_da_plataforma(): void
    {
        ChannelAdSpend::create([
            'date' => now()->toDateString(),
            'channel' => 'google_ads',
            'impressions' => 1000,
            'clicks' => 40,
            'attributed_orders' => 3,
            'attributed_gmv' => 300,
            'spend' => 100,
        ]);

        $this->actingAs($this->admin())->get('/admin/publicidade/google-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('conectado', true)
                ->where('ultimaSincronizacao', now()->toDateString())
                ->where('periodos.0.totais.gasto', 100)
                ->where('periodos.0.totais.cliques', 40)
                ->where('periodos.0.totais.roas', 3)
                ->where('periodos.1.totais.gasto', 0));
    }

    public function test_meta_ads_mostra_historico_das_campanhas_excluidas(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('historico', 13)
                ->where('historico.0.nome', 'Parafusadeira 18V Leve | Mulheres | Vendas')
                ->where('historico.0.inicio', '2026-10-25')
                ->where('historico.0.gasto', 35.64)
                ->where('historico.12.nome', '[VENDAS] Umidificador e Difusor de Aromas Chama de Carvão')
                ->where('historico.12.compras', 1)
                ->where('historico.12.vendas', 145.72));

        $this->assertEqualsWithDelta(1302.33, (float) \DB::table('meta_ads_campanhas_historico')->sum('gasto'), 0.001);
        $this->assertEqualsWithDelta(476.24, (float) \DB::table('meta_ads_campanhas_historico')->sum('vendas'), 0.001);
        $this->assertSame(3, (int) \DB::table('meta_ads_campanhas_historico')->sum('compras'));
    }

    public function test_google_ads_nao_recebe_historico_da_meta(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/google-ads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('historico'));
    }

    public function test_cliente_nao_acessa(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($cliente)->get('/admin/publicidade/meta-ads')->assertStatus(403);
    }
}

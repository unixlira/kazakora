<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menu Meta com a FerraKora (pedido do Lira 2026-11-11): a mesma tela de
 * campanha > conjuntos > anúncios lê a conta de anúncios da FerraKora com
 * ?loja=ferrakora; status ao vivo e pausar também vão para a conta dela.
 * Graph API falsa: act_111 = KazaKora, act_777 = FerraKora.
 */
class MetaAdsCampanhasFerraKoraTest extends TestCase
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

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $nivel = $request->data()['level'] ?? null;
            if (preg_match('#/(act_\d+)/#', $url, $m)) {
                $this->contasLidas[] = $m[1];
            }
            $p = str_contains($url, '/act_777/') ? 'fk' : 'kz';
            $metricas = fn (string $id) => [$nivel.'_id' => $id, 'spend' => '20.00', 'impressions' => '1000', 'reach' => '800', 'clicks' => '40'];

            return match (true) {
                str_contains($url, '/campaigns') => Http::response(['data' => [
                    ['id' => "{$p}c1", 'name' => strtoupper($p).' | VENDAS', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES', 'daily_budget' => '2000'],
                ]]),
                str_contains($url, '/adsets') => Http::response(['data' => [
                    ['id' => "{$p}s1", 'name' => 'Conjunto', 'campaign_id' => "{$p}c1", 'effective_status' => 'ACTIVE'],
                ]]),
                str_contains($url, '/ads') => Http::response(['data' => [
                    ['id' => ($p === 'fk' ? '7001' : '1001'), 'name' => 'AD01', 'adset_id' => "{$p}s1", 'campaign_id' => "{$p}c1", 'effective_status' => 'ACTIVE'],
                    ['id' => ($p === 'fk' ? '7002' : '1002'), 'name' => 'AD02', 'adset_id' => "{$p}s1", 'campaign_id' => "{$p}c1", 'effective_status' => 'ACTIVE'],
                ]]),
                str_contains($url, '/insights') && $nivel === 'account' => Http::response(['data' => [['spend' => '20.00', 'impressions' => '1000', 'inline_link_clicks' => '40']]]),
                str_contains($url, '/insights') => Http::response(['data' => array_map($metricas,
                    ['campaign' => ["{$p}c1"], 'adset' => ["{$p}s1"], 'ad' => [($p === 'fk' ? '7001' : '1001'), ($p === 'fk' ? '7002' : '1002')]][$nivel])]),
                // Ler o anúncio antes de pausar: account_id confere a conta da loja.
                str_contains($url, '/v23.0/7001') && $request->method() === 'GET' => Http::response(['id' => '7001', 'name' => 'AD01', 'account_id' => '777', 'campaign_id' => 'fkc1', 'adset_id' => 'fks1', 'effective_status' => 'ACTIVE']),
                str_contains($url, '/v23.0/7001') => Http::response(['success' => true]),
                default => Http::response(['error' => ['message' => 'inesperado '.$url]], 400),
            };
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_aba_ferrakora_mostra_campanha_conjunto_e_anuncios_da_conta_dela(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas?loja=ferrakora')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lojasMeta.loja', 'ferrakora')
                ->where('lojasMeta.lojas', ['kazakora' => 'KazaKora', 'ferrakora' => 'FerraKora'])
                ->has('campanhas', 0)
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('espelho.erro', null)
                    ->has('espelho.campanhas', 1)
                    ->where('espelho.campanhas.0.id', 'fkc1')
                    ->where('espelho.campanhas.0.conjuntos.0.id', 'fks1')
                    ->has('espelho.campanhas.0.conjuntos.0.anuncios', 2)));

        $this->assertNotEmpty($this->contasLidas);
        $this->assertSame(['act_777'], array_values(array_unique($this->contasLidas)));
    }

    public function test_sem_loja_continua_na_kazakora(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/meta-ads/campanhas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lojasMeta.loja', 'kazakora')
                ->loadDeferredProps(fn ($reload) => $reload->where('espelho.campanhas.0.id', 'kzc1')));

        $this->assertSame(['act_111'], array_values(array_unique($this->contasLidas)));
    }

    public function test_status_ao_vivo_e_pausar_vao_para_a_conta_da_ferrakora(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/publicidade/meta-ads/gerenciar/status?loja=ferrakora')
            ->assertOk()
            ->assertJsonPath('meta.anuncios.7001', 'ACTIVE')
            ->assertJsonPath('locais', []);

        $this->actingAs($admin)->postJson('/admin/publicidade/meta-ads/gerenciar/anuncio/7001/pausar?loja=ferrakora')
            ->assertSuccessful();

        // Na KazaKora o mesmo anúncio é recusado: não é da conta dela.
        $this->actingAs($admin)->postJson('/admin/publicidade/meta-ads/gerenciar/anuncio/7001/pausar')
            ->assertStatus(422);
    }
}

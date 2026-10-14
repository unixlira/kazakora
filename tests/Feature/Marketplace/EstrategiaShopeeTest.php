<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\Diagnostico\EstrategiaShopee;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publicidade › Shopee › Estratégia (tarefas 568/569): ADS com prejuízo e a
 * decisão do Cauê pelas regras da reunião (meta = empate + 2, conversão
 * mínima de 1,65%).
 */
class EstrategiaShopeeTest extends TestCase
{
    use RefreshDatabase;

    private function shopee(): void
    {
        config(['loja.simples.aliquota' => 4.5]);
        // Sem venda no período: empate estimado com taxa média de 20%.
        // Preço 100 → lucro = 100 − 20 − 4,5 − custo.
        foreach (['111' => 60, '222' => 70, '333' => 50, '444' => 30] as $item => $custo) {
            $p = Product::factory()->create(['name' => "Produto {$item}", 'cost_price' => $custo]);
            ProductChannelListing::create(['product_id' => $p->id, 'channel' => 'shopee', 'status' => 'published', 'external_id' => $item]);
        }

        $metricas = [
            // gasto, gmv, pedidos, cliques, impressões
            1 => [100, 150, 1, 200, 10000],  // ROAS 1,5, conversão 0,5%
            2 => [50, 600, 6, 200, 1000],    // ROAS 12, conversão 3%
            3 => [30, 0, 0, 6, 600],         // sem venda, CTR 1%
            4 => [40, 400, 2, 200, 1000],    // ROAS 10, conversão 1%
        ];

        $this->mock(ShopeeClient::class, function ($m) use ($metricas) {
            $m->shouldReceive('get')->andReturnUsing(fn (string $path) => match ($path) {
                '/api/v2/product/get_item_base_info' => ['response' => ['item_list' => array_map(
                    fn ($i) => ['item_id' => $i, 'item_name' => "Anúncio {$i}", 'price_info' => [['current_price' => 100]], 'image' => ['image_url_list' => ["https://img/{$i}.jpg"]]],
                    [111, 222, 333, 444],
                )]],
                '/api/v2/ads/get_product_level_campaign_id_list' => ['response' => ['has_next_page' => false, 'campaign_list' => array_map(fn ($c) => ['campaign_id' => $c], [1, 2, 3, 4])]],
                '/api/v2/ads/get_product_level_campaign_setting_info' => ['response' => ['campaign_list' => array_map(
                    fn ($c, $item) => ['campaign_id' => $c, 'common_info' => ['campaign_status' => 'ongoing', 'item_id_list' => [$item]], 'auto_bidding_info' => ['roas_target' => 5]],
                    [1, 2, 3, 4], [111, 222, 333, 444],
                )]],
                '/api/v2/ads/get_product_campaign_daily_performance' => ['response' => ['campaign_list' => array_map(
                    fn ($c) => ['campaign_id' => $c, 'metrics_list' => [[
                        'expense' => $metricas[$c][0], 'broad_gmv' => $metricas[$c][1], 'broad_order' => $metricas[$c][2],
                        'clicks' => $metricas[$c][3], 'impression' => $metricas[$c][4],
                    ]]],
                    [1, 2, 3, 4],
                )]],
                default => ['response' => []],
            });
        });
    }

    public function test_lista_prejuizo_com_a_decisao_do_caue(): void
    {
        $this->shopee();
        $d = app(EstrategiaShopee::class)->gerar(30);
        $l = collect($d['linhas'])->keyBy('item_id');

        // Pior resultado primeiro.
        $this->assertSame(['111', '333', '222', '444'], collect($d['linhas'])->pluck('item_id')->all());

        // 111: empate 100/15,5 = 6,5, ROAS 1,5 e conversão 0,5% → pausar.
        $this->assertSame(6.5, $l['111']['roas_empate']);
        $this->assertSame(8.5, $l['111']['meta_sugerida']);
        $this->assertSame(0.5, $l['111']['conversao']);
        $this->assertSame(['PAUSAR'], $l['111']['decisoes']);
        $this->assertSame(-76.75, $l['111']['resultado']);
        $this->assertSame('https://img/111.jpg', $l['111']['imagem']);
        $this->assertSame('prejuizo', $l['111']['grupo']);

        // 222: converte 3% mas empate 18,2 (margem 5,5%) → meta e preço, sem pausa.
        $this->assertSame(['META', 'PRECO'], $l['222']['decisoes']);
        $this->assertSame(20.2, $l['222']['meta_sugerida']);

        // 333: R$ 30 sem venda e CTR 1% → pausar e trocar capa.
        $this->assertSame(['PAUSAR', 'CAPA'], $l['333']['decisoes']);

        // 444: dá lucro, mas converte 1% (abaixo de 1,65%).
        $this->assertSame('conversao_baixa', $l['444']['grupo']);
        $this->assertSame([], $l['444']['decisoes']);

        $this->assertSame(3, $d['kpis']['com_prejuizo']);
        $this->assertSame(220.0, $d['kpis']['gasto']);
        $this->assertSame(-123.75, $d['kpis']['prejuizo']);
    }

    public function test_regua_de_conversao_por_empate(): void
    {
        $this->assertTrue(EstrategiaShopee::margemAguenta(0.8, 3.9));
        $this->assertFalse(EstrategiaShopee::margemAguenta(0.8, 4.0));
        $this->assertFalse(EstrategiaShopee::margemAguenta(1.63, 5.1));
        $this->assertTrue(EstrategiaShopee::margemAguenta(1.8, 6.0));
        $this->assertTrue(EstrategiaShopee::margemAguenta(3.55, 8.0));
        $this->assertFalse(EstrategiaShopee::margemAguenta(3.55, 10.9));
        $this->assertFalse(EstrategiaShopee::margemAguenta(null, 3.0));
    }

    public function test_tela_abre_para_o_admin(): void
    {
        $this->shopee();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/publicidade/shopee/estrategia?dias=15')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Publicidade/ShopeeEstrategia', false)
            ->where('dias', 15)
            ->where('dados.kpis.com_prejuizo', 3)
            ->where('dados.linhas.0.item_id', '111'));

        $this->actingAs($admin)->post('/admin/publicidade/shopee/estrategia/atualizar', ['dias' => 15])
            ->assertRedirect('/admin/publicidade/shopee/estrategia?dias=15');
    }
}

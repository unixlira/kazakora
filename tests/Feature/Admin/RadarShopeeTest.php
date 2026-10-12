<?php

namespace Tests\Feature\Admin;

use App\Models\ShopeeRadarColeta;
use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Radar da Shopee (pedido do Lira 2026-10-12): ranking "Produtos em alta"
 * da Central do Vendedor importado por arquivo e mostrado em
 * Oportunidades › Shopee.
 */
class RadarShopeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.testing.ensure_pages_exist' => false]);
    }

    private function arquivo(): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'radar-shopee');
        file_put_contents($caminho, json_encode([
            'coletado_em' => '2026-10-12T18:30:00-03:00',
            'categorias' => [[
                'l1_cat_id' => 100636, 'l1_cat_name' => 'Casa e Decoração',
                'l2_cat_id' => 100716, 'l2_cat_name' => 'Artigos de Cuidados com a Casa',
                'l3_categories' => [
                    ['l3_cat_id' => 101213, 'l3_cat_name' => 'Produtos de Limpeza', 'l3_unit_sold' => 421417],
                    ['l3_cat_id' => 101214, 'l3_cat_name' => 'Lavanderia', 'l3_unit_sold' => 174374],
                ],
            ]],
            'produtos' => [
                ['itemid' => 58256439593, 'shopid' => 761647073, 'image' => 'br-abc', 'title' => 'Percarbonato 100% Puro', 'price' => 16.49, 'min_price' => 16.49, 'max_price' => 48.99, 'status' => 1, 'units_sold' => 71458, 'l1_cat_id' => 100636, 'l2_cat_id' => 100716, 'l3_cat_id' => 101213],
                ['itemid' => 111, 'shopid' => 1773475655, 'image' => 'br-def', 'title' => 'Varal da KazaKora', 'price' => 30, 'min_price' => 30, 'max_price' => 30, 'status' => 1, 'units_sold' => 900, 'l1_cat_id' => 100636, 'l2_cat_id' => 100716, 'l3_cat_id' => 101214],
                ['itemid' => 58256439593, 'shopid' => 761647073, 'image' => 'br-abc', 'title' => 'Percarbonato 100% Puro', 'price' => 16.49, 'min_price' => 16.49, 'max_price' => 48.99, 'status' => 1, 'units_sold' => 71458, 'l1_cat_id' => 100636, 'l2_cat_id' => 100716, 'l3_cat_id' => 101213],
            ],
        ]));

        return $caminho;
    }

    public function test_importa_categorias_e_produtos_sem_repetir(): void
    {
        $this->artisan('shopee:radar-importar', ['arquivo' => $this->arquivo()])->assertSuccessful();

        $coleta = ShopeeRadarColeta::query()->sole();
        $this->assertSame(2, $coleta->total_categorias);
        $this->assertSame(2, $coleta->total_produtos);
        $this->assertSame('2026-10-12 18:30', $coleta->coletado_em->format('Y-m-d H:i'));
        $this->assertSame(['Produtos de Limpeza', 'Lavanderia'], $coleta->categorias()->orderBy('posicao')->pluck('l3_nome')->all());
    }

    public function test_arquivo_vazio_nao_cria_coleta(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'radar-shopee');
        file_put_contents($caminho, '{"categorias": [], "produtos": []}');

        $this->artisan('shopee:radar-importar', ['arquivo' => $caminho])->assertFailed();
        $this->assertSame(0, ShopeeRadarColeta::query()->count());
    }

    public function test_tela_da_shopee_mostra_a_ultima_coleta(): void
    {
        MarketplaceAccount::query()->create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '1773475655', 'connected_at' => now(),
        ]);
        $this->artisan('shopee:radar-importar', ['arquivo' => $this->arquivo()])->assertSuccessful();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/admin/oportunidades/shopee')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Oportunidades/Mercado', false)
                ->missing('radarShopee')
                ->loadDeferredProps('radar', fn (Assert $reload) => $reload
                    ->has('radarShopee.grupos', 1)
                    ->where('radarShopee.grupos.0.l2_nome', 'Artigos de Cuidados com a Casa')
                    ->where('radarShopee.grupos.0.unidades_vendidas', 595791)
                    ->has('radarShopee.produtos', 2)
                    ->where('radarShopee.produtos.0.nome', 'Percarbonato 100% Puro')
                    ->where('radarShopee.produtos.0.categoria', 'Produtos de Limpeza')
                    ->where('radarShopee.produtos.0.link', 'https://shopee.com.br/product/761647073/58256439593')
                    ->where('radarShopee.produtos.0.voce_vende', false)
                    ->where('radarShopee.produtos.1.voce_vende', true)));
    }
}

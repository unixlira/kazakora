<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\Diagnostico\DiagnosticoShopee;
use App\Modules\Marketplace\Support\Diagnostico\EconomiaDosAnuncios;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Seções do Cauê (Shopee) e do Diego (ML) no dashboard (2026-10-11):
 * lucro real por anúncio, ROAS de empate/meta e alertas acionáveis.
 */
class DiagnosticoCanaisTest extends TestCase
{
    use RefreshDatabase;

    public function test_indicadores_de_roas_pela_margem(): void
    {
        config(['loja.simples.aliquota' => 4.5]);
        // Preço 100, lucro antes do ADS 25: empate 4; meta = 100 / (25 - 15) = 10.
        $i = EconomiaDosAnuncios::indicadores(100, 25, 40, 0.2);
        $this->assertSame(4.0, $i['roas_empate']);
        $this->assertSame(10.0, $i['roas_meta']);
        $this->assertSame(25.0, $i['margem']);
        // Preço mínimo para 15%: 40 / (1 - 0,20 - 0,045 - 0,15) = 66,12.
        $this->assertSame(66.12, $i['preco_minimo']);

        $sem = EconomiaDosAnuncios::indicadores(100, 10);
        $this->assertSame(10.0, $sem['roas_empate']);
        $this->assertNull($sem['roas_meta'], 'margem abaixo de 15% não tem meta possível');
    }

    private function venda(Product $produto, float $preco, int $qtd, float $taxa): void
    {
        $pedido = Order::create([
            'status' => Order::STATUS_COMPLETED, 'origin' => 'shopee', 'external_order_id' => uniqid(),
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '1', 'shipping_street' => 'R',
            'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP',
            'subtotal' => $preco * $qtd, 'total' => $preco * $qtd,
        ]);
        DB::table('order_items')->insert(['order_id' => $pedido->id, 'product_id' => $produto->id, 'product_name' => $produto->name,
            'product_price' => $preco, 'quantity' => $qtd, 'subtotal' => $preco * $qtd, 'created_at' => now(), 'updated_at' => now()]);
        OrderChannelFee::create(['order_id' => $pedido->id, 'channel' => 'shopee', 'gross_amount' => $preco * $qtd, 'fee_amount' => $taxa, 'source' => 'api', 'computed_at' => now()]);
    }

    public function test_cauê_aponta_ads_com_prejuizo_capa_fraca_e_oportunidade(): void
    {
        config(['loja.simples.aliquota' => 4.5]);
        $caro = Product::factory()->create(['name' => 'Ring Light', 'cost_price' => 20, 'stock' => 10]);
        $bom = Product::factory()->create(['name' => 'Cesto', 'cost_price' => 10, 'stock' => 10]);
        ProductChannelListing::create(['product_id' => $caro->id, 'channel' => 'shopee', 'status' => 'published', 'external_id' => '111']);
        ProductChannelListing::create(['product_id' => $bom->id, 'channel' => 'shopee', 'status' => 'published', 'external_id' => '222']);
        $this->venda($caro, 50, 10, 100);   // receita 500, taxa 100, custo 200, imposto 22,50 → lucro 17,75/un
        $this->venda($bom, 50, 20, 200);    // lucro 25,75/un

        $this->mock(ShopeeClient::class, function ($m) {
            $m->shouldReceive('get')->andReturnUsing(function (string $path) {
                return match ($path) {
                    '/api/v2/product/get_item_base_info' => ['response' => ['item_list' => [
                        ['item_id' => 111, 'item_name' => 'Ring Light', 'item_status' => 'NORMAL', 'price_info' => [['current_price' => 50]], 'image' => ['image_url_list' => ['a', 'b', 'c']], 'video_info' => []],
                        ['item_id' => 222, 'item_name' => 'Cesto', 'item_status' => 'NORMAL', 'price_info' => [['current_price' => 50]], 'image' => ['image_url_list' => array_fill(0, 9, 'x')], 'video_info' => [['v' => 1]]],
                    ]]],
                    '/api/v2/product/get_item_extra_info' => ['response' => ['item_list' => [
                        ['item_id' => 111, 'views' => 500, 'sale' => 10, 'rating_star' => 4.8, 'comment_count' => 30],
                        ['item_id' => 222, 'views' => 400, 'sale' => 40, 'rating_star' => 4.9, 'comment_count' => 50],
                    ]]],
                    '/api/v2/ads/get_product_level_campaign_id_list' => ['response' => ['has_next_page' => false, 'campaign_list' => [['campaign_id' => 9]]]],
                    '/api/v2/ads/get_product_level_campaign_setting_info' => ['response' => ['campaign_list' => [
                        ['campaign_id' => 9, 'common_info' => ['campaign_status' => 'ongoing', 'item_id_list' => [111]], 'auto_bidding_info' => ['roas_target' => 3]],
                    ]]],
                    '/api/v2/ads/get_product_campaign_daily_performance' => ['response' => ['campaign_list' => [
                        ['campaign_id' => 9, 'metrics_list' => [['expense' => 100, 'broad_gmv' => 200, 'broad_order' => 4, 'clicks' => 10, 'impression' => 1000]]],
                    ]]],
                    default => ['response' => []],
                };
            });
        });

        $d = app(DiagnosticoShopee::class)->gerar();
        $textos = collect($d['alertas'])->groupBy(fn ($a) => $a['produto']['item_id'])->map(fn ($g) => $g->pluck('texto')->implode(' | '));

        // Ring Light: ROAS 2 < empate 50/17,75 = 2,8 → prejuízo; CTR 1% → capa; 3 fotos; sem vídeo.
        $this->assertStringContainsString('ADS dando prejuízo', $textos['111']);
        $this->assertStringContainsString('Capa fraca', $textos['111']);
        $this->assertStringContainsString('Só 3 fotos', $textos['111']);
        $this->assertStringContainsString('Sem vídeo', $textos['111']);
        // Cesto: converte 10% e aguenta ADS → oportunidade.
        $this->assertStringContainsString('Oportunidade', $textos['222']);
        $this->assertSame('P0', $d['alertas'][0]['prioridade']);
        $this->assertSame(2, $d['kpis']['pedidos']);
        $this->assertSame(2.0, $d['kpis']['ads_roas']);
    }

    public function test_dashboard_entrega_os_diagnosticos(): void
    {
        DB::table('diagnosticos_canal')->insert(['canal' => 'shopee', 'dados' => json_encode(['kpis' => ['pedidos' => 3], 'alertas' => []]), 'gerado_em' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn ($page) => $page
            ->where('diagnosticos.shopee.kpis.pedidos', 3)
            ->where('diagnosticos.mercado_livre', null));
    }
}

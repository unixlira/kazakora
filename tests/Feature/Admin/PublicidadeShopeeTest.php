<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Models\ShopeeAdCampanha;
use App\Modules\Marketplace\Models\ShopeeAdCampanhaDia;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Publicidade › Estratégia Shopee (2026-10-14): resultado do ADS em reais,
 * situação de cada campanha e a cópia só-leitura das campanhas da API.
 */
class PublicidadeShopeeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
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

    private function campanha(int $id, string $item, array $dia, string $status = 'ongoing', ?float $meta = null): void
    {
        ShopeeAdCampanha::create(['campaign_id' => $id, 'ad_type' => 'manual', 'lance' => 'auto', 'nome' => "C{$id}", 'status' => $status,
            'item_id' => $item, 'itens' => [$item], 'meta_roas' => $meta, 'orcamento' => 10, 'sincronizado_em' => now()]);
        ShopeeAdCampanhaDia::create(['campaign_id' => $id, 'data' => now()->subDay()->toDateString()] + $dia);
    }

    public function test_tela_mostra_resultado_e_situacao_de_cada_campanha(): void
    {
        config(['loja.simples.aliquota' => 4.5]);
        // Preço 50, taxa 20%, custo 20, imposto 2,25 → lucro 17,75/un, margem 35,5%, empate 2,8, meta 6,0.
        $ring = Product::factory()->create(['name' => 'Ring Light', 'cost_price' => 20, 'stock' => 10]);
        $cesto = Product::factory()->create(['name' => 'Cesto', 'cost_price' => 20, 'stock' => 100]);
        $tomada = Product::factory()->create(['name' => 'Extensão', 'cost_price' => 20, 'stock' => 5]);
        foreach ([[$ring, '111'], [$cesto, '222'], [$tomada, '333']] as [$p, $item]) {
            ProductChannelListing::create(['product_id' => $p->id, 'channel' => 'shopee', 'status' => 'published', 'external_id' => $item, 'price' => 50]);
            $this->venda($p, 50, 10, 100);
        }

        // ROAS 2 < empate 2,8: prejuízo de 200 × 0,355 − 100 = −29.
        $this->campanha(1, '111', ['gasto' => 100, 'venda' => 200, 'impressoes' => 3000, 'cliques' => 100, 'pedidos' => 4], meta: 3);
        // ROAS 10 ≥ meta 6 × 1,3, conversão 5%: escalar; resultado 500 × 0,355 − 50 = +127,5.
        $this->campanha(2, '222', ['gasto' => 50, 'venda' => 500, 'impressoes' => 2000, 'cliques' => 200, 'pedidos' => 10]);
        // R$ 31,71 sem venda: queimando.
        $this->campanha(3, '333', ['gasto' => 31.71, 'venda' => 0, 'impressoes' => 900, 'cliques' => 40, 'pedidos' => 0]);
        // Encerrada e sem gasto no período: fora da lista.
        $this->campanha(4, '111', ['gasto' => 0, 'venda' => 0], status: 'closed');
        ChannelAdSpend::create(['date' => now()->subDay()->toDateString(), 'channel' => 'shopee', 'spend' => 181.71, 'attributed_gmv' => 700]);

        $this->mock(ShopeeAdsService::class, fn ($m) => $m->shouldReceive('currentBalance')->andReturn(143.5));

        $this->actingAs($this->admin())->get('/admin/publicidade/shopee')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Publicidade/Shopee', false)
                ->where('saldo', 143.5)
                ->where('filtro.dias', 30)
                ->has('estrategia.campanhas', 3)
                // Maior prejuízo em reais primeiro.
                ->where('estrategia.campanhas.0.campaign_id', 3)
                ->where('estrategia.campanhas.0.situacao', 'queimando')
                ->where('estrategia.campanhas.1.campaign_id', 1)
                ->where('estrategia.campanhas.1.situacao', 'prejuizo')
                ->where('estrategia.campanhas.1.roas_empate', 2.8)
                ->where('estrategia.campanhas.1.resultado', -29)
                ->where('estrategia.campanhas.1.causa', 'lance')
                ->where('estrategia.campanhas.2.situacao', 'escalar')
                ->where('estrategia.campanhas.2.resultado', 127.5)
                ->where('estrategia.kpis.em_prejuizo', 2)
                ->where('estrategia.kpis.gasto_sem_venda', 31.71)
                ->where('estrategia.conferencia.confere', true)
                ->has('estrategia.decidir', 2));
    }

    public function test_produto_sem_custo_nao_vira_prejuizo_nem_lucro(): void
    {
        $p = Product::factory()->create(['name' => 'Sem custo', 'cost_price' => null, 'stock' => 3]);
        ProductChannelListing::create(['product_id' => $p->id, 'channel' => 'shopee', 'status' => 'published', 'external_id' => '444', 'price' => 50]);
        $this->campanha(5, '444', ['gasto' => 40, 'venda' => 60, 'impressoes' => 1000, 'cliques' => 50, 'pedidos' => 1]);
        $this->mock(ShopeeAdsService::class, fn ($m) => $m->shouldReceive('currentBalance')->andThrow(new \RuntimeException('IP')));

        $this->actingAs($this->admin())->get('/admin/publicidade/shopee?dias=7')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('saldo', null)
                ->where('estrategia.campanhas.0.situacao', 'sem_custo')
                ->where('estrategia.campanhas.0.resultado', null));
    }

    public function test_periodo_invalido_e_recusado(): void
    {
        $this->actingAs($this->admin())->get('/admin/publicidade/shopee?dias=5')->assertSessionHasErrors('dias');
    }

    public function test_comando_copia_campanhas_e_dias_sem_duplicar(): void
    {
        MarketplaceAccount::create(['channel' => MarketplaceAccount::CHANNEL_SHOPEE, 'status' => MarketplaceAccount::STATUS_CONNECTED, 'seller_id' => '1', 'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addDay()]);
        $this->mock(ShopeeAdsService::class, function ($m) {
            $m->shouldReceive('productCampaignIds')->andReturn([9]);
            $m->shouldReceive('productCampaignSettings')->andReturn([[
                'campaign_id' => 9,
                'common_info' => ['ad_type' => 'manual', 'ad_name' => 'Ring', 'campaign_status' => 'ongoing', 'bidding_method' => 'auto',
                    'campaign_placement' => 'all', 'campaign_budget' => 15, 'campaign_duration' => ['start_time' => 1776135600, 'end_time' => 0], 'item_id_list' => [111]],
                'auto_bidding_info' => ['roas_target' => 7.5],
            ]]);
            $m->shouldReceive('productCampaignDailyPerformance')->andReturn([[
                'campaign_id' => 9, 'date' => now()->toDateString(), 'impressions' => 100, 'clicks' => 5, 'spend' => 3.5,
                'broad_orders' => 1, 'broad_units' => 1, 'broad_gmv' => 50, 'direct_orders' => 1, 'direct_gmv' => 50,
            ]]);
        });

        $this->artisan('shopee:ads-campanhas', ['--dias' => 3])->assertSuccessful();
        $this->artisan('shopee:ads-campanhas', ['--dias' => 3])->assertSuccessful();

        $c = ShopeeAdCampanha::query()->sole();
        $this->assertSame('111', $c->item_id);
        $this->assertSame('7.50', $c->meta_roas);
        $this->assertSame('ongoing', $c->status);
        $this->assertSame(1, ShopeeAdCampanhaDia::query()->count());
        $this->assertSame('3.50', ShopeeAdCampanhaDia::query()->value('gasto'));
    }
}

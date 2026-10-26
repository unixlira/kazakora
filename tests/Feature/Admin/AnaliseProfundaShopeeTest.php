<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Models\ShopeeMetrica;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Estatísticas › Análise Profunda de Conta · Shopee (2026-10-26): lucro pela
 * ContributionMargin, funil da tela (shopee_metricas) e só as regras de
 * alerta "Em uso" e "Lira" da especificação.
 */
class AnaliseProfundaShopeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00'));
        config(['loja.simples.aliquota' => 4.5]);
        $this->mock(ShopeeAdsService::class, fn ($m) => $m->shouldReceive('currentBalance')->andReturn(80.0));
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

    private function venda(Product $produto, float $preco, int $qtd, float $taxa, string $quando): void
    {
        $pedido = Order::create([
            'status' => Order::STATUS_COMPLETED, 'origin' => 'shopee', 'external_order_id' => uniqid(),
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '1', 'shipping_street' => 'R',
            'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP',
            'subtotal' => $preco * $qtd, 'total' => $preco * $qtd,
        ]);
        $pedido->forceFill(['created_at' => $quando])->save();
        DB::table('order_items')->insert(['order_id' => $pedido->id, 'product_id' => $produto->id, 'product_name' => $produto->name,
            'product_price' => $preco, 'quantity' => $qtd, 'subtotal' => $preco * $qtd, 'created_at' => $quando, 'updated_at' => $quando]);
        OrderChannelFee::create(['order_id' => $pedido->id, 'channel' => 'shopee', 'gross_amount' => $preco * $qtd, 'fee_amount' => $taxa, 'source' => 'api', 'computed_at' => now()]);
    }

    private function metrica(string $item, string $metrica, float $valor, string $unidade = '', string $escopo = 'produto', string $fonte = 'tela'): void
    {
        ShopeeMetrica::create([
            'chave' => ShopeeMetrica::chave($escopo, $item, null, '2026-10-19', '2026-10-25', null, null, $metrica),
            'coletado_em' => '2026-10-26 07:10:00', 'fonte' => $fonte, 'periodo_inicio' => '2026-10-19', 'periodo_fim' => '2026-10-25',
            'escopo' => $escopo, 'item_id' => $item, 'nome' => 'Ring Light da tela', 'metrica' => $metrica, 'valor' => $valor, 'unidade' => $unidade,
        ]);
    }

    public function test_abre_em_7_dias_com_lucro_oficial_funil_da_tela_e_alertas_em_uso(): void
    {
        // Ring Light: preço 50, custo 30, taxa 10 → lucro 50 − 10 − 30 − 2,25 = 7,75/un, 15,5% (acima do piso).
        $ring = Product::factory()->create(['name' => 'Ring Light', 'cost_price' => 30, 'stock' => 10]);
        ProductChannelListing::create(['product_id' => $ring->id, 'channel' => 'shopee', 'status' => 'published', 'external_id' => '111', 'price' => 50]);
        $this->venda($ring, 50, 2, 20, '2026-10-20 10:00:00');
        $this->venda($ring, 50, 2, 20, '2026-10-22 21:00:00');

        // Cesto: preço 20, custo 25 → prejuízo antes do ADS (R1).
        $cesto = Product::factory()->create(['name' => 'Cesto', 'cost_price' => 25, 'stock' => 100]);
        ProductChannelListing::create(['product_id' => $cesto->id, 'channel' => 'shopee', 'status' => 'published', 'external_id' => '222', 'price' => 20]);
        $this->venda($cesto, 20, 1, 4, '2026-10-21 15:00:00');

        // Fora do período: não entra na conta.
        $this->venda($ring, 50, 5, 50, '2026-10-26 09:00:00');

        // Tela: CTR 1,5% com 2.000 impressões (R9), conversão 0,5% com 400 visitantes (R10).
        foreach (['impressoes' => [2000, ''], 'cliques' => [30, ''], 'ctr' => [1.5, '%'], 'visitantes' => [400, ''], 'conversao' => [0.5, '%'],
            'taxa_rejeicao' => [30, '%'], 'add_carrinho_compradores' => [25, ''], 'pedidos' => [2, ''], 'pedidos_pagos' => [2, '']] as $m => [$v, $u]) {
            $this->metrica('111', $m, $v, $u);
        }
        // ADS da coleta (sem campanha da API no período): R$ 25 sem venda (R5).
        foreach (['gasto_ads' => 25, 'venda_ads' => 0, 'pedidos_ads' => 0, 'cliques_ads' => 40, 'impressoes_ads' => 1000, 'ads_ativo' => 1] as $m => $v) {
            $this->metrica('111', $m, $v, $m === 'gasto_ads' ? 'R$' : '', 'anuncio_ads', 'api');
        }

        $this->actingAs($this->admin())->get('/admin/estatisticas/analise-profunda/shopee')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Estatisticas/AnaliseProfunda', false)
                ->where('filtro.dias', 7)
                ->where('filtro.desde', '2026-10-19')
                ->where('filtro.ate', '2026-10-25')
                ->where('saldo', 80)
                // Conta oficial: receita 220 (4 Ring + 1 Cesto), sem o pedido de hoje.
                ->where('analise.conta.receita', 220)
                ->where('analise.conta.pedidos', 3)
                ->where('analise.funil.fonte.tipo', 'periodo')
                ->where('analise.funil.loja.ctr', 1.5)
                ->where('analise.funil.loja.visitantes', 400)
                ->where('analise.ads.fonte.tipo', 'coleta')
                ->where('analise.ads.kpis.gasto', 25)
                ->where('analise.horarios.pedidos', 3)
                ->where('analise.riscos', function ($riscos) {
                    $codigos = collect($riscos)->flatMap(fn ($r) => collect($r['alertas'])->pluck('codigo'))->sort()->values()->all();
                    // R1 (Cesto); R5, R9, R10 e R11 (Ring: 25 no carrinho, 2 pedidos = 92% de abandono).
                    $this->assertSame(['R1', 'R10', 'R11', 'R5', 'R9'], $codigos);
                    // Grave primeiro.
                    $this->assertSame('grave', $riscos[0]['gravidade']);

                    return true;
                })
            );
    }

    public function test_alerta_de_regua_nova_nao_aparece(): void
    {
        // Rejeição altíssima e pedido feito → pago baixo (R12 e R13, réguas "Nova"): sem alerta.
        foreach (['visitantes' => [500, ''], 'taxa_rejeicao' => [95, '%'], 'pedidos' => [20, ''], 'pedidos_pagos' => [5, ''],
            'conversao' => [4, '%'], 'impressoes' => [5000, ''], 'cliques' => [500, ''], 'ctr' => [10, '%']] as $m => [$v, $u]) {
            $this->metrica('333', $m, $v, $u);
        }

        $this->actingAs($this->admin())->get('/admin/estatisticas/analise-profunda/shopee')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('analise.riscos', [])
                ->where('analise.funil.loja.taxa_pagamento', 25)
            );
    }

    public function test_periodo_sem_coleta_exata_usa_a_mais_proxima_e_avisa(): void
    {
        $this->metrica('111', 'visitantes', 400);

        $this->actingAs($this->admin())->get('/admin/estatisticas/analise-profunda/shopee?dias=15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtro.desde', '2026-10-11')
                ->where('analise.funil.fonte.tipo', 'aproximado')
                ->where('analise.funil.fonte.inicio', '2026-10-19')
            );
    }

    public function test_so_shopee_e_so_com_permissao_financeira(): void
    {
        $this->get('/admin/estatisticas/analise-profunda/shopee')->assertRedirect();
        $cliente = $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))->get('/admin/estatisticas/analise-profunda/shopee');
        $this->assertContains($cliente->status(), [302, 403]);
        $this->actingAs($this->admin())->get('/admin/estatisticas/analise-profunda/mercado-livre')->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Support\Indicadores\PainelDeAlertas;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Indicadores com alerta (2026-10-14): últimos 7 dias completos contra os 7
 * anteriores, por canal, com alerta de queda de ROAS, CTR baixo, CPC/CPA
 * alto, margem, cancelamento e estoque.
 */
class IndicadoresAlertasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedido(string $canal, string $quando, float $valor, string $status = Order::STATUS_COMPLETED, ?Product $produto = null, int $qtd = 1): Order
    {
        $o = Order::create([
            'status' => $status, 'origin' => $canal, 'external_order_id' => uniqid(),
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '1', 'shipping_street' => 'R',
            'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP',
            'subtotal' => $valor, 'total' => $valor,
        ]);
        $o->forceFill(['created_at' => Carbon::parse($quando), 'updated_at' => Carbon::parse($quando)])->save();
        if ($produto) {
            DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $produto->id, 'product_name' => $produto->name,
                'product_price' => $valor / $qtd, 'quantity' => $qtd, 'subtotal' => $valor, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $o;
    }

    private function ads(string $canal, string $data, int $imp, int $cliques, int $pedidos, float $venda, float $gasto): void
    {
        ChannelAdSpend::create(['date' => $data, 'channel' => $canal, 'impressions' => $imp, 'clicks' => $cliques,
            'attributed_orders' => $pedidos, 'attributed_gmv' => $venda, 'spend' => $gasto]);
    }

    private function alerta(array $r, string $indicador, ?string $canal = null): ?array
    {
        return collect($r['alertas'])->first(fn ($a) => $a['indicador'] === $indicador && ($canal === null || $a['canal'] === $canal));
    }

    public function test_compara_ultimos_dias_completos_e_acusa_queda_de_faturamento(): void
    {
        $produto = Product::factory()->create(['cost_price' => 10, 'stock' => 500]);
        // Anterior (30/09 a 06/10): 10 × R$ 100. Atual (07/10 a 13/10): 4 × R$ 100. Hoje não conta.
        for ($i = 0; $i < 10; $i++) {
            $this->pedido('shopee', '2026-10-02 10:00', 100, produto: $produto);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->pedido('shopee', '2026-10-10 10:00', 100, produto: $produto);
        }
        $this->pedido('shopee', '2026-10-14 09:00', 5000, produto: $produto);

        $r = app(PainelDeAlertas::class)->gerar(7);

        $this->assertSame('2026-10-07', $r['desde']);
        $this->assertSame('2026-10-13', $r['ate']);
        $shopee = collect($r['canais'])->firstWhere('canal', 'shopee');
        $fat = collect($shopee['metricas'])->firstWhere('chave', 'faturamento');
        $this->assertEquals(400, $fat['valor']);
        $this->assertEquals(1000, $fat['anterior']);
        $this->assertEquals(-60, $fat['variacao']);

        $a = $this->alerta($r, 'Faturamento', 'shopee');
        $this->assertSame(PainelDeAlertas::CRITICO, $a['nivel']);
        $this->assertStringContainsString('caiu 60,0%', $a['texto']);
    }

    public function test_ads_abaixo_do_empate_ctr_baixo_e_cpc_alto(): void
    {
        $produto = Product::factory()->create(['cost_price' => 60, 'stock' => 500]);
        // Atual: receita 1000, custo 600 → margem antes do ADS 400, empate 2,5.
        for ($i = 0; $i < 10; $i++) {
            $this->pedido('shopee', '2026-10-09 10:00', 100, produto: $produto);
        }
        $this->ads('shopee', '2026-10-09', 20000, 200, 4, 400, 200);   // ROAS 2,0, CTR 1%, CPC 1,00
        $this->ads('shopee', '2026-10-02', 10000, 300, 9, 900, 150);   // CPC 0,50

        $r = app(PainelDeAlertas::class)->gerar(7);

        $this->assertSame(PainelDeAlertas::CRITICO, $this->alerta($r, 'ROAS', 'shopee')['nivel']);
        $this->assertStringContainsString('abaixo do empate', $this->alerta($r, 'ROAS', 'shopee')['texto']);
        $this->assertNotNull($this->alerta($r, 'CTR', 'shopee'));
        $this->assertStringContainsString('100,0% mais caro', $this->alerta($r, 'CPC', 'shopee')['texto']);
        // TACOS = 200 / 1000 = 20% → crítico.
        $this->assertSame(PainelDeAlertas::CRITICO, $this->alerta($r, 'TACOS', 'shopee')['nivel']);

        $roas = collect(collect($r['canais'])->firstWhere('canal', 'shopee')['metricas'])->firstWhere('chave', 'roas');
        $this->assertEquals(2, $roas['valor']);
        $this->assertSame('empate 2,5', $roas['referencia']);
    }

    public function test_queda_de_roas_sem_passar_do_empate_e_atencao(): void
    {
        $produto = Product::factory()->create(['cost_price' => 10, 'stock' => 500]);
        for ($i = 0; $i < 10; $i++) {
            $this->pedido('shopee', '2026-10-09 10:00', 100, produto: $produto);
        }
        $this->ads('shopee', '2026-10-09', 5000, 150, 6, 600, 60);    // ROAS 10, CTR 3%
        $this->ads('shopee', '2026-10-02', 5000, 150, 9, 900, 60);    // ROAS 15

        $r = app(PainelDeAlertas::class)->gerar(7);

        $a = $this->alerta($r, 'ROAS', 'shopee');
        $this->assertSame(PainelDeAlertas::ATENCAO, $a['nivel']);
        $this->assertStringContainsString('caiu 33,3%', $a['texto']);
        $this->assertNull($this->alerta($r, 'CTR', 'shopee'));
    }

    public function test_operacao_envio_atrasado_ruptura_e_sem_custo(): void
    {
        $semEstoque = Product::factory()->create(['name' => 'Pipoqueira', 'cost_price' => 10, 'stock' => 0, 'is_active' => true]);
        $semCusto = Product::factory()->create(['name' => 'Sem custo', 'cost_price' => null, 'stock' => 100, 'is_active' => true]);
        for ($i = 0; $i < 3; $i++) {
            $this->pedido('mercado_livre', '2026-10-05 10:00', 50, produto: $semEstoque);
        }
        $this->pedido('shopee', '2026-10-11 10:00', 80, produto: $semCusto);
        // Pago há 3 dias sem envio; o do TikTok não conta (status fica "pago" pelo Bling).
        $this->pedido('shopee', '2026-10-11 10:00', 80, Order::STATUS_PAID);
        $this->pedido('tiktok_shop', '2026-10-11 10:00', 80, Order::STATUS_PAID);

        $r = app(PainelDeAlertas::class)->gerar(7);

        $this->assertStringContainsString('Pipoqueira', $this->alerta($r, 'Ruptura')['texto']);
        $this->assertStringContainsString('1 produto(s) vendidos', $this->alerta($r, 'Produto sem custo')['texto']);
        $this->assertStringContainsString('Shopee: 1 pedido(s) pago(s)', $this->alerta($r, 'Envio atrasado', 'shopee')['texto']);
        $this->assertNull($this->alerta($r, 'Envio atrasado', 'tiktok_shop'));
        // Críticos primeiro.
        $this->assertSame(PainelDeAlertas::CRITICO, $r['alertas'][0]['nivel']);
    }

    public function test_tela_indicadores_mostra_o_painel_e_aceita_o_periodo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/indicadores?dias=30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('painel.dias', 30)
                ->where('painel.desde', '2026-09-14')
                ->has('painel.alertas')
                ->has('kpis.ordersMonth'));

        // Período fora da lista volta para 7.
        $this->actingAs($admin)->get('/admin/indicadores?dias=999')
            ->assertInertia(fn ($page) => $page->where('painel.dias', 7));
    }

    public function test_comando_lista_os_alertas(): void
    {
        $this->artisan('indicadores:alertas')->assertSuccessful();
    }
}

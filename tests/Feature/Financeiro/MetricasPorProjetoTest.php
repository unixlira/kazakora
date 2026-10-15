<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Admin\Support\Indicadores\SerieDiaria;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Métrica por projeto (pedido do Lira 2026-10-14): o pedido do FerraKora e
 * do Beauty Kora continua origin "loja" na operação, mas nas métricas vira
 * canal próprio (Order::canalSql). "loja" passa a ser só o kazakora.com, e a
 * soma dos canais continua batendo com o total do grupo (periodo()).
 */
class MetricasPorProjetoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedido(array $pedido, float $subtotal, ?float $correios = null): Order
    {
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '85999999999', 'shipping_zip' => '60025-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Fortaleza', 'shipping_state' => 'CE',
            'subtotal' => $subtotal, 'shipping_cost' => 0, 'total' => $subtotal,
        ], $pedido));
        $order->items()->create([
            'product_id' => null, 'product_name' => 'Avulso', 'product_price' => $subtotal, 'quantity' => 1,
            'subtotal' => $subtotal, 'manual_cost_price' => round($subtotal / 2, 2),
        ]);

        if ($correios !== null) {
            CorreiosPrePostagem::create([
                'order_id' => $order->id, 'status' => CorreiosPrePostagem::STATUS_GERADA, 'postage_price' => $correios,
                'customer_name' => 'Cliente', 'zip' => '60025000', 'street' => 'Rua X', 'number' => '1',
                'neighborhood' => 'Centro', 'city' => 'Fortaleza', 'state' => 'CE',
                'service_code' => '03298', 'weight_grams' => 300, 'content_items' => [],
            ]);
        }

        return $order;
    }

    public function test_projeto_vira_canal_proprio_e_a_soma_bate_com_o_periodo(): void
    {
        $this->pedido([], 100.0, 20.0);
        $this->pedido(['site_origem' => Order::SITE_FERRAKORA], 300.0, 35.0);
        $this->pedido(['site_origem' => Order::SITE_BEAUTYKORA], 80.0);
        $this->pedido(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP1'], 50.0);

        $desde = now()->startOfDay();
        $ate = now()->addDay()->startOfDay();
        $margem = app(ContributionMargin::class);
        $canais = $margem->porCanal($desde, $ate);
        $periodo = $margem->periodo($desde, $ate);

        // kazakora.com sozinho em "loja".
        $this->assertSame(1, $canais['loja']['pedidos']);
        $this->assertSame(100.0, $canais['loja']['receita']);
        $this->assertSame(50.0, $canais['loja']['custo_produtos']);
        $this->assertSame(20.0, $canais['loja']['frete']);

        // FerraKora e Beauty Kora com a mesma conta, em canal próprio.
        $this->assertSame(1, $canais['ferrakora']['pedidos']);
        $this->assertSame(300.0, $canais['ferrakora']['receita']);
        $this->assertSame(35.0, $canais['ferrakora']['frete']);
        $this->assertSame(300.0 - 150.0 - 35.0, $canais['ferrakora']['margem']);
        $this->assertSame(80.0, $canais['beautykora']['receita']);
        $this->assertSame(50.0, $canais['shopee']['receita']);

        // Nada muda no total do grupo.
        foreach (['receita', 'custo_produtos', 'taxas', 'frete', 'ads', 'margem'] as $campo) {
            $this->assertEqualsWithDelta($periodo[$campo], array_sum(array_column($canais, $campo)), 0.001, $campo);
        }
        $this->assertSame(530.0, $periodo['receita']);

        // Filtro por canal: porItem e o scope doCanal seguem a mesma chave.
        $this->assertCount(1, $margem->porItem($desde, $ate, 'loja')['linhas']);
        $this->assertEqualsWithDelta(300.0, array_sum(array_column($margem->porItem($desde, $ate, 'ferrakora')['linhas'], 'receita')), 0.001);
        $this->assertSame(1, Order::query()->doCanal('loja')->count());
        $this->assertSame(1, Order::query()->doCanal('ferrakora')->count());
        $this->assertSame(1, Order::query()->doCanal('shopee')->count());
    }

    public function test_serie_diaria_separa_o_projeto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'America/Sao_Paulo'));
        $this->pedido([], 100.0);
        $this->pedido(['site_origem' => Order::SITE_FERRAKORA], 300.0);
        Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'America/Sao_Paulo'));

        $serie = app(SerieDiaria::class)->gerar();

        $this->assertSame(100.0, array_sum($serie['canais']['loja']['receita']));
        $this->assertSame(300.0, array_sum($serie['canais']['ferrakora']['receita']));
        $this->assertSame(1, array_sum($serie['canais']['ferrakora']['todos']));
    }
}

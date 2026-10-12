<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TURBO do site (pedido do Lira em 2026-10-12): o que o cliente paga pelo
 * TURBO não é receita, abate o SEDEX que a loja paga na pré-postagem.
 */
class LucroTurboTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 15:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedido(array $pedido = []): Order
    {
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID, 'origin' => 'loja',
            'shipping_name' => 'Cliente', 'shipping_phone' => '85999999999', 'shipping_zip' => '60025-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Fortaleza', 'shipping_state' => 'CE',
            'subtotal' => 120.5, 'shipping_cost' => 10.25, 'shipping_carrier_name' => FreteEmbutido::TURBO, 'total' => 130.75,
        ], $pedido));
        $order->items()->create(['product_id' => null, 'product_name' => 'Avulso', 'product_price' => 120.5, 'quantity' => 1, 'subtotal' => 120.5]);

        return $order;
    }

    private function prePostagem(Order $order, float $preco): void
    {
        CorreiosPrePostagem::create([
            'order_id' => $order->id, 'status' => CorreiosPrePostagem::STATUS_GERADA, 'postage_price' => $preco,
            'customer_name' => 'Cliente', 'zip' => '60025000', 'street' => 'Rua X', 'number' => '1',
            'neighborhood' => 'Centro', 'city' => 'Fortaleza', 'state' => 'CE',
            'service_code' => FreteEmbutido::SEDEX, 'weight_grams' => 300, 'content_items' => [],
        ]);
    }

    public function test_turbo_abate_o_frete_e_nao_entra_na_receita(): void
    {
        $this->prePostagem($this->pedido(), 30.5);
        // Sem etiqueta ainda: o TURBO não abate nada (frete não fica negativo).
        $this->pedido();

        $desde = now()->startOfDay();
        $ate = now()->endOfDay();
        $periodo = app(ContributionMargin::class)->periodo($desde, $ate);
        $site = app(ContributionMargin::class)->porCanal($desde, $ate)['loja'];

        $this->assertSame(241.0, $periodo['receita']);
        // SEDEX R$ 30,50 − TURBO R$ 10,25 que o cliente pagou.
        $this->assertSame(20.25, $periodo['frete']);
        $this->assertSame(20.25, $site['frete']);
        $this->assertSame(241.0 - 20.25, $site['margem']);

        $porItem = app(ContributionMargin::class)->porItem($desde, $ate, 'loja');
        $this->assertEqualsWithDelta(20.25, array_sum(array_column($porItem['linhas'], 'frete')), 0.001);
    }
}

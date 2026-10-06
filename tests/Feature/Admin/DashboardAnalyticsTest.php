<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pedido do usuário 2026-10-06: métricas e gráficos da dashboard com os
 * dados reais. O ponto central: o mês até hoje é comparado com os MESMOS
 * dias do mês passado, nunca com o mês passado inteiro.
 */
class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function venda(string $quando, float $valor, string $canal = Order::ORIGIN_SHOPEE, int $itens = 1, string $status = Order::STATUS_PAID): void
    {
        $order = Order::create([
            'status' => $status, 'origin' => $canal,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => $valor, 'total' => $valor,
        ]);
        $order->forceFill(['created_at' => Carbon::parse($quando)])->save();
        $order->items()->create(['product_name' => 'Item', 'product_price' => $valor / $itens, 'quantity' => $itens, 'subtotal' => $valor]);
    }

    public function test_kpis_compare_with_the_same_days_of_last_month(): void
    {
        Carbon::setTestNow('2026-10-06 15:00:00');

        $this->venda('2026-10-02 10:00', 300, Order::ORIGIN_TIKTOK_SHOP, 2);
        $this->venda('2026-10-05 10:00', 100);
        $this->venda('2026-10-05 11:00', 999, status: Order::STATUS_CANCELLED);
        // Mesmos dias do mês passado (1 a 6/09): 200. Resto de setembro: 1000.
        $this->venda('2026-09-03 10:00', 200);
        $this->venda('2026-09-20 10:00', 1000);

        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('analytics.kpis.crescimento', 100)
            ->where('analytics.kpis.receitaPeriodoAnterior', 200)
            ->where('analytics.kpis.receitaMesAnterior', 1200)
            ->where('analytics.kpis.ticketMedio', 200)
            ->where('analytics.kpis.itensPorPedido', 1.5)
            // 400 em 6 dias, mês de 31 → 2066,67
            ->where('analytics.kpis.projecaoMes', 2066.67)
            ->where('analytics.ritmo.dia', 6)
            ->where('analytics.ritmo.atual', [0, 300, 300, 300, 400, 400])
            ->where('analytics.mensal', fn ($meses) => collect($meses)->pluck('mes')->all() === ['2026-09', '2026-10']
                && collect($meses)->last()['canais']['tiktok_shop'] == 300));
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-17 ("as métricas não estão funcionando", relatado pelo
 * usuário): DashboardController::revenueByChannel() nunca teve filtro de
 * data — somava TODO pedido pago desde o início da loja, enquanto
 * 'stats.revenue' ao lado (mesma tela, mesmo rótulo "Faturamento") já era
 * escopado pro mês corrente desde 2026-08-06. A soma "por canal" dava maior
 * que o "faturamento do mês" no mesmo carregamento — visivelmente quebrado.
 */
class DashboardRevenueByChannelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $attributes));
    }

    public function test_revenue_by_channel_excludes_orders_from_before_the_current_month(): void
    {
        $thisMonthOrder = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'subtotal' => 150.50, 'total' => 150.50]);

        $lastMonthOrder = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'subtotal' => 999.99, 'total' => 999.99]);
        $lastMonthOrder->forceFill(['created_at' => now()->subMonthNoOverflow()])->save();

        $response = $this->actingAs($this->admin())->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('revenueByChannel', [['origin' => Order::ORIGIN_SHOPEE, 'total' => 150.50]]));
    }

    /**
     * A garantia central do bug: os dois valores vêm da MESMA definição de
     * "faturamento do mês" (mesmo PAID_STATUSES, mesma coluna subtotal,
     * mesmo corte de data) — pra um único canal no mês, a soma de
     * revenueByChannel bate exatamente com stats.revenue. Um pedido de 2
     * meses atrás (fora do mês corrente) inflaria os dois se o bug do
     * filtro de data voltasse — nunca só um dos dois sozinho.
     */
    public function test_revenue_by_channel_matches_the_monthly_revenue_stat_for_a_single_channel(): void
    {
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'subtotal' => 150.50, 'total' => 150.50]);

        $oldOrder = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'subtotal' => 500, 'total' => 500]);
        $oldOrder->forceFill(['created_at' => now()->subMonths(2)])->save();

        $response = $this->actingAs($this->admin())->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.revenue', 150.50)
            ->where('revenueByChannel', [['origin' => Order::ORIGIN_SHOPEE, 'total' => 150.50]]));
    }
}

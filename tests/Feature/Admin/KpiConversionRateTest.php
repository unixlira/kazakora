<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-17 ("as métricas do kazakora ainda têm informação
 * incorreta", achado ao vivo: taxa de conversão mostrando 200%):
 * conversionRate comparava TODO pedido válido do mês (qualquer canal —
 * Shopee, Mercado Livre, etc.) contra uniqueVisitorsMonth, que só existe
 * pra visita real na loja própria (SiteVisit não rastreia marketplace
 * nenhum). Um comprador que nunca visitou o site (comprou direto no app da
 * Shopee) não pode contar como "conversão" desse funil.
 */
class KpiConversionRateTest extends TestCase
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

    public function test_conversion_rate_ignores_marketplace_orders_that_never_visited_the_store(): void
    {
        SiteVisit::create(['ip' => '10.0.0.1', 'path' => '/']);
        SiteVisit::create(['ip' => '10.0.0.2', 'path' => '/']);

        // 5 pedidos Shopee — comprador nunca visitou o site, não pode
        // contar como "conversão" desse funil.
        for ($i = 0; $i < 5; $i++) {
            $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => "SHP-{$i}"]);
        }

        $response = $this->actingAs($this->admin())->get('/admin/indicadores');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('kpis.uniqueVisitorsMonth', 2)
            ->where('kpis.ordersMonth', 5)
            ->where('kpis.conversionRate', 0.0));
    }

    public function test_conversion_rate_counts_only_store_orders_against_site_visitors(): void
    {
        SiteVisit::create(['ip' => '10.0.0.1', 'path' => '/']);
        SiteVisit::create(['ip' => '10.0.0.2', 'path' => '/']);
        SiteVisit::create(['ip' => '10.0.0.3', 'path' => '/']);
        SiteVisit::create(['ip' => '10.0.0.4', 'path' => '/']);

        $this->makeOrder(['origin' => Order::ORIGIN_STORE]);
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP-1']);

        $response = $this->actingAs($this->admin())->get('/admin/indicadores');

        $response->assertOk();
        // 1 pedido da loja / 4 visitantes = 25%, o pedido Shopee não entra.
        $response->assertInertia(fn ($page) => $page->where('kpis.conversionRate', 25.0));
    }
}

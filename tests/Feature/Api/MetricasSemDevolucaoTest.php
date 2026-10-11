<?php

namespace Tests\Feature\Api;

use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2026-10-11: 51 notas de entrada de devolução viraram "65 vendas" no KoraSync. */
class MetricasSemDevolucaoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $origem, string $status): void
    {
        Order::create([
            'status' => $status, 'origin' => $origem, 'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01001000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP',
            'shipping_state' => 'SP', 'subtotal' => 10, 'total' => 10,
        ]);
    }

    public function test_vendas_de_hoje_contam_so_venda_capturada(): void
    {
        config(['services.print_agent.token' => 'agente']);
        $this->pedido(Order::ORIGIN_SHOPEE, Order::STATUS_PAID);
        $this->pedido(Order::ORIGIN_TIKTOK_SHOP, Order::STATUS_SHIPPED);
        $this->pedido(Order::ORIGIN_SALES_RETURN_INVOICE, Order::STATUS_COMPLETED);
        $this->pedido(Order::ORIGIN_STORE, Order::STATUS_AWAITING_PAYMENT);

        $this->withHeader('Authorization', 'Bearer agente')
            ->getJson('/api/print-agent/dashboard/metrics')
            ->assertOk()
            ->assertJsonPath('sales_today', 2);
    }
}

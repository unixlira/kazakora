<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-18 (pedido #476, Shopee 260819PQEFC0TQ): `orders:expire-
 * abandoned` cancelava e devolvia estoque de um pedido de MARKETPLACE só
 * porque `created_at` (forçado pro `placed_at` real da venda no canal, ver
 * OrderImportService::createOrder()) já passava dos 30 min — mesmo o
 * pedido tendo sido pago de verdade no canal segundos depois, confirmado
 * via webhook `READY_TO_SHIP`. A varredura foi desenhada só pro checkout
 * direto da loja (janela do Pix/cartão, ver comentário em
 * ExpireAbandonedOrders::ABANDONED_AFTER_MINUTES) — pedido de canal tem seu
 * status real controlado pelo próprio marketplace via webhook/backfill
 * horário, nunca deveria ser cancelado por essa varredura.
 */
class ExpireAbandonedOrdersMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $origin, ?string $externalOrderId = null): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => $origin,
            'external_order_id' => $externalOrderId,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 44.99,
            'total' => 44.99,
        ]);

        // Simula tanto um pedido de loja parado há muito tempo (created_at
        // natural) quanto um pedido de canal com placed_at antigo (mesmo
        // efeito de OrderImportService::createOrder() forçando created_at
        // pro instante real da venda no marketplace) — os 30 min já
        // estourados nos dois casos.
        $order->forceFill(['created_at' => now()->subHours(2)])->save();

        return $order->fresh();
    }

    public function test_expires_abandoned_store_order(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_STORE);

        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $order->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->stock_restored_at);
    }

    public function test_does_not_expire_marketplace_order_even_if_old(): void
    {
        $order = $this->makeOrder(Order::ORIGIN_SHOPEE, '260819PQEFC0TQ');

        $this->artisan('orders:expire-abandoned')->assertSuccessful();

        $order->refresh();
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertNull($order->stock_restored_at);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-21 (pedido #559, importado errado como "loja"
 * em vez do canal certo — TikTok Shop): admin precisa poder corrigir o
 * canal (origin) direto na tela do pedido, sem mexer em mais nada. Ver
 * OrderController::update().
 */
class OrderChannelCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'external_order_id' => null,
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '100',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $overrides));
    }

    public function test_admin_corrects_the_channel_of_a_wrongly_imported_order(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->createOrder(['external_order_id' => '585661946933315493']);

        $this->actingAs($admin)
            ->patch("/admin/pedidos/{$order->id}", ['status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_TIKTOK_SHOP])
            ->assertRedirect();

        $this->assertSame(Order::ORIGIN_TIKTOK_SHOP, $order->fresh()->origin);
    }

    /**
     * Chamador que só manda status (todo caller antigo, ver
     * OrderStatusNotificationTest) continua funcionando igual — origin
     * ausente do payload não vira null gravado no banco.
     */
    public function test_updating_status_alone_does_not_touch_the_origin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->createOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE]);

        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}", ['status' => Order::STATUS_SHIPPED]);

        $this->assertSame(Order::ORIGIN_MERCADO_LIVRE, $order->fresh()->origin);
    }

    public function test_cannot_switch_to_a_channel_where_another_order_already_has_the_same_external_id(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->createOrder(['origin' => Order::ORIGIN_TIKTOK_SHOP, 'external_order_id' => 'DUP-1']);
        $order = $this->createOrder(['origin' => Order::ORIGIN_STORE, 'external_order_id' => 'DUP-1']);

        $response = $this->actingAs($admin)
            ->patch("/admin/pedidos/{$order->id}", ['status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_TIKTOK_SHOP]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(Order::ORIGIN_STORE, $order->fresh()->origin);
    }

    public function test_rejects_an_unknown_channel(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->createOrder();

        $this->actingAs($admin)
            ->patch("/admin/pedidos/{$order->id}", ['status' => Order::STATUS_PAID, 'origin' => 'canal_inventado'])
            ->assertSessionHasErrors('origin');
    }
}

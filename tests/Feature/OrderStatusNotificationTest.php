<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(User $customer): Order
    {
        return Order::create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_PENDING,
            'shipping_name' => $customer->name,
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '100',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    public function test_changing_order_status_notifies_the_customer(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->createOrder($customer);

        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}", ['status' => Order::STATUS_PAID])
            ->assertRedirect();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $customer->fresh()->unreadNotifications()->count());
        $this->assertStringContainsString('Pago', $customer->fresh()->notifications()->first()->data['message']);
    }

    public function test_updating_to_the_same_status_does_not_create_a_notification(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->createOrder($customer);

        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}", ['status' => Order::STATUS_PENDING]);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_user_can_mark_a_notification_as_read(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->createOrder($customer);

        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}", ['status' => Order::STATUS_PAID]);

        $notification = $customer->fresh()->notifications()->first();

        $this->actingAs($customer)->post("/notificacoes/{$notification->id}/lida")->assertRedirect();

        $this->assertSame(0, $customer->fresh()->unreadNotifications()->count());
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $otherCustomer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->createOrder($customer);

        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}", ['status' => Order::STATUS_PAID]);

        $notification = $customer->fresh()->notifications()->first();

        $this->actingAs($otherCustomer)->post("/notificacoes/{$notification->id}/lida")->assertRedirect();

        $this->assertSame(1, $customer->fresh()->unreadNotifications()->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InvoiceIssuanceFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cobre o bug real reportado: alertas operacionais internos (NF-e falhou,
 * etiqueta presa, estoque negativo) apareciam na sineta da LOJA (AppLayout)
 * quando o usuário logado era admin — a mesma prop compartilhada
 * (HandleInertiaRequests::notificationsFor()) alimenta os dois layouts sem
 * filtrar por contexto. Isolamento por conta (notifiable_id) já era
 * garantido pelo Laravel — o que faltava era isolamento por CONTEXTO
 * (admin x loja) pro mesmo usuário.
 */
class NotificationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeNotification(User $user, string $type, string $message): void
    {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['message' => $message],
            'read_at' => null,
        ]);
    }

    public function test_admin_only_notification_is_hidden_on_the_storefront(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeNotification($admin, InvoiceIssuanceFailedNotification::class, 'Falha ao emitir NF-e do pedido #1');
        $this->makeNotification($admin, 'App\\Notifications\\OrderStatusUpdated', 'Seu pedido foi enviado');

        $response = $this->actingAs($admin)->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('notifications.unreadCount', 1)
            ->has('notifications.items', 1)
            ->where('notifications.items.0.message', 'Seu pedido foi enviado'));
    }

    public function test_admin_only_notification_is_visible_inside_the_admin_area(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->makeNotification($admin, InvoiceIssuanceFailedNotification::class, 'Falha ao emitir NF-e do pedido #1');
        $this->makeNotification($admin, 'App\\Notifications\\OrderStatusUpdated', 'Seu pedido foi enviado');

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->where('notifications.unreadCount', 2)
            ->has('notifications.items', 2));
    }

    public function test_a_customer_never_sees_another_users_notifications(): void
    {
        $customerA = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customerB = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->makeNotification($customerB, 'App\\Notifications\\OrderStatusUpdated', 'Notificação da Fulana B');

        $response = $this->actingAs($customerA)->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('notifications.unreadCount', 0)
            ->has('notifications.items', 0));
    }
}

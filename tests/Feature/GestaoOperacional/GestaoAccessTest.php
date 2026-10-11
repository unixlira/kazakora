<?php

namespace Tests\Feature\GestaoOperacional;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestaoAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_staff_roles_can_view_read_only_reports_and_kpis(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUBSCRIBER] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get('/admin/relatorios')->assertOk();
            $this->actingAs($user)->get('/admin/indicadores')->assertOk();
            $this->actingAs($user)->get('/admin/dashboard-financeiro')->assertOk();
            $this->actingAs($user)->get('/admin/estoque')->assertOk();
            $this->actingAs($user)->get('/admin/logistica')->assertOk();
        }
    }

    public function test_customer_cannot_reach_any_gestao_or_operacional_route(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get('/admin/relatorios')->assertForbidden();
        $this->actingAs($customer)->get('/admin/fluxo-de-caixa')->assertForbidden();
        $this->actingAs($customer)->get('/admin/pedidos-de-compra')->assertForbidden();
        $this->actingAs($customer)->get('/admin/ordens-de-servico')->assertForbidden();
    }

    public function test_subscriber_cannot_create_shipping_methods(): void
    {
        $subscriber = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);

        $this->actingAs($subscriber)->post('/admin/logistica', [
            'name' => 'Expresso',
            'estimated_days' => 2,
            'price' => 19.9,
        ])->assertForbidden();
    }
}

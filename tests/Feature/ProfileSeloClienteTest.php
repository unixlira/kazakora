<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileSeloClienteTest extends TestCase
{
    use RefreshDatabase;

    private function pedidos(User $cliente, int $quantos, string $status, string $criadoEm): void
    {
        for ($i = 0; $i < $quantos; $i++) {
            Order::create([
                'user_id' => $cliente->id, 'status' => $status, 'origin' => Order::ORIGIN_STORE,
                'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01001000',
                'shipping_street' => 'Rua', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
                'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'total' => 100,
            ])->forceFill(['created_at' => $criadoEm])->save();
        }
    }

    private function seloNoPerfil(User $cliente): ?string
    {
        $selo = null;
        $this->actingAs($cliente)->get('/perfil')->assertOk()
            ->assertInertia(function (Assert $page) use (&$selo) {
                $selo = $page->toArray()['props']['seloCliente'] ?? null;
            });

        return $selo;
    }

    public function test_cliente_novo_e_super_cliente(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->assertSame('super_cliente', $this->seloNoPerfil($cliente));
    }

    public function test_mais_de_dez_finalizados_vira_cinco_estrelas(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->pedidos($cliente, 10, Order::STATUS_COMPLETED, '2026-10-20 10:00:00');

        $this->assertSame('super_cliente', $this->seloNoPerfil($cliente), '10 ainda não é mais de 10');

        $this->pedidos($cliente, 1, Order::STATUS_COMPLETED, '2026-10-21 10:00:00');

        $this->assertSame('cinco_estrelas', $this->seloNoPerfil($cliente));
    }

    public function test_pedidos_anteriores_e_nao_finalizados_nao_contam(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->pedidos($cliente, 20, Order::STATUS_COMPLETED, '2026-10-14 23:59:00');
        $this->pedidos($cliente, 20, Order::STATUS_SHIPPED, '2026-10-20 10:00:00');

        $this->assertSame('super_cliente', $this->seloNoPerfil($cliente));
    }

    public function test_equipe_nao_tem_selo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->assertNull($this->seloNoPerfil($admin));
    }
}

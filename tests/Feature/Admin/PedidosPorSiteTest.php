<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * FerraKora separado da KazaKora no admin (pedido do Lira 2026-10-15):
 * filtro de site em Pedidos e cliente do FerraKora fora de Clientes.
 */
class PedidosPorSiteTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(array $extra = []): Order
    {
        return Order::create([
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
            ...$extra,
        ]);
    }

    public function test_filtro_de_site_separa_os_pedidos(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $kazakora = $this->pedido();
        $cliente = FerraKoraCliente::create(['name' => 'Ana', 'email' => 'ana@exemplo.com', 'cpf' => '12345678909', 'password' => 'segredo123']);
        $ferrakora = $this->pedido(['site_origem' => Order::SITE_FERRAKORA, 'ferrakora_cliente_id' => $cliente->id, 'buyer_document' => '12345678909']);

        $lista = fn (string $url, array $ids) => $this->actingAs($admin)->get($url)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('orders.data', fn ($pedidos) => collect($pedidos)->pluck('id')->sort()->values()->all() === $ids));

        $lista('/admin/pedidos?site=ferrakora', [$ferrakora->id]);
        $lista('/admin/pedidos?site=kazakora', [$kazakora->id]);
        $lista('/admin/pedidos', [$kazakora->id, $ferrakora->id]);
        $lista('/admin/pedidos?site=qualquer', [$kazakora->id, $ferrakora->id]);
    }

    public function test_cliente_do_ferrakora_nao_aparece_em_clientes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->pedido(['buyer_document' => '52998224725', 'shipping_name' => 'Cliente KazaKora']);
        $cliente = FerraKoraCliente::create(['name' => 'Ana', 'email' => 'ana@exemplo.com', 'cpf' => '12345678909', 'password' => 'segredo123']);
        $this->pedido(['site_origem' => Order::SITE_FERRAKORA, 'ferrakora_cliente_id' => $cliente->id, 'buyer_document' => '12345678909', 'shipping_name' => 'Ana']);

        $this->actingAs($admin)->get('/admin/clientes')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('customers.data', 1)
                ->where('customers.data.0.name', 'Cliente KazaKora'));
    }
}

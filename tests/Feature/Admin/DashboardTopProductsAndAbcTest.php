<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido do usuário 2026-10-06: no lugar de Pedidos recentes e Estoque
 * baixo, a dashboard mostra os mais vendidos (30 dias) e a curva ABC pelo
 * faturamento (90 dias): A até 80%, B até 95%.
 */
class DashboardTopProductsAndAbcTest extends TestCase
{
    use RefreshDatabase;

    private function venda(Product $produto, int $quantidade, float $preco, array $pedido = []): Order
    {
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_SHOPEE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $quantidade * $preco, 'total' => $quantidade * $preco,
        ], $pedido));
        $order->items()->create([
            'product_id' => $produto->id, 'product_name' => $produto->name, 'product_price' => $preco,
            'quantity' => $quantidade, 'subtotal' => $quantidade * $preco,
        ]);

        return $order;
    }

    public function test_top_products_and_abc_curve(): void
    {
        // Faturamento: grande 700 (70%), medio 200 (90% acumulado),
        // pequeno 60 (96%), minimo 40 (100%).
        $grande = Product::factory()->create(['name' => 'Grande']);
        $medio = Product::factory()->create(['name' => 'Medio']);
        $pequeno = Product::factory()->create(['name' => 'Pequeno']);
        $minimo = Product::factory()->create(['name' => 'Minimo']);

        $this->venda($grande, 2, 350);
        $this->venda($medio, 4, 50);
        $this->venda($pequeno, 6, 10);
        $this->venda($minimo, 8, 5);
        // Fora: cancelado e venda de 4 meses atrás.
        $this->venda($minimo, 50, 100, ['status' => Order::STATUS_CANCELLED]);
        $this->venda($pequeno, 50, 100)->forceFill(['created_at' => now()->subMonths(4)])->save();

        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('topProducts.0.name', 'Minimo')
            ->where('topProducts.0.quantity', 8)
            ->where('abcCurve.total', 1000)
            ->where('abcCurve.a', fn ($a) => collect($a)->pluck('name')->all() === ['Grande', 'Medio'])
            ->where('abcCurve.b', fn ($b) => collect($b)->pluck('name')->all() === ['Pequeno'])
            ->missing('recentOrders')
            ->missing('lowStockProducts'));
    }
}

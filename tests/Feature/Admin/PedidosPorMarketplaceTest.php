<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Filtro "Marketplace" da lista de pedidos do admin (pedido do Lira
 * 2026-10-16): um select com os canais reais; cada opção mostra só os
 * pedidos daquele canal e "Todos" mostra tudo.
 */
class PedidosPorMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $origin, array $extra = []): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => $origin,
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

    private function idsDaLista(User $admin, string $url): array
    {
        $ids = null;

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$ids) {
                $ids = collect($page->toArray()['props']['orders']['data'])->pluck('id')->sort()->values()->all();
            });

        return $ids;
    }

    public function test_cada_marketplace_mostra_so_os_seus_pedidos_e_todos_mostra_tudo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $pedidos = [
            Order::ORIGIN_STORE => $this->pedido(Order::ORIGIN_STORE),
            Order::ORIGIN_SHOPEE => $this->pedido(Order::ORIGIN_SHOPEE),
            Order::ORIGIN_MERCADO_LIVRE => $this->pedido(Order::ORIGIN_MERCADO_LIVRE),
            Order::ORIGIN_TIKTOK_SHOP => $this->pedido(Order::ORIGIN_TIKTOK_SHOP),
            Order::ORIGIN_AMAZON => $this->pedido(Order::ORIGIN_AMAZON),
            Order::SITE_BEAUTYKORA => $this->pedido(Order::ORIGIN_STORE, ['site_origem' => Order::SITE_BEAUTYKORA]),
        ];

        foreach ($pedidos as $canal => $pedido) {
            $this->assertSame([$pedido->id], $this->idsDaLista($admin, "/admin/pedidos?marketplace={$canal}"), "canal {$canal}");
        }

        $todos = collect($pedidos)->pluck('id')->sort()->values()->all();
        $this->assertSame($todos, $this->idsDaLista($admin, '/admin/pedidos'));
        $this->assertSame($todos, $this->idsDaLista($admin, '/admin/pedidos?marketplace='));
        $this->assertSame($todos, $this->idsDaLista($admin, '/admin/pedidos?marketplace=inventado'));

        // Link antigo ?origin= continua funcionando.
        $this->assertSame([$pedidos[Order::ORIGIN_SHOPEE]->id], $this->idsDaLista($admin, '/admin/pedidos?origin=shopee'));
    }

    public function test_opcoes_do_select_vem_da_fonte_unica_e_o_filtro_volta_marcado(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/pedidos?marketplace=tiktok_shop&search=Cliente')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('marketplaces', collect(Order::MARKETPLACES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all())
                ->where('marketplaces.0', ['value' => 'loja', 'label' => 'Site'])
                ->where('filters.marketplace', 'tiktok_shop')
                ->where('filters.search', 'Cliente'));
    }

    public function test_filtro_de_marketplace_combina_com_busca_e_paginacao(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        for ($i = 0; $i < 51; $i++) {
            $this->pedido(Order::ORIGIN_SHOPEE, ['shipping_name' => 'Maria Shopee']);
        }
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, ['shipping_name' => 'Maria ML']);
        $this->pedido(Order::ORIGIN_SHOPEE, ['shipping_name' => 'João Shopee']);

        $this->actingAs($admin)->get('/admin/pedidos?marketplace=shopee&search=Maria')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.total', 51)
                ->where('orders.last_page', 2)
                ->where('orders.next_page_url', fn ($url) => str_contains($url, 'marketplace=shopee') && str_contains($url, 'search=Maria') && str_contains($url, 'page=2')));

        $this->actingAs($admin)->get('/admin/pedidos?marketplace=shopee&search=Maria&page=2')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 1));
    }
}

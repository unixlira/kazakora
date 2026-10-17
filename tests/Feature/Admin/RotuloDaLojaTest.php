<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Site" virou o nome da loja (pedido do Lira 2026-10-16): pedido com
 * origin "loja" aparece como KazaKora, FerraKora ou Beauty Kora conforme o
 * site_origem. O valor gravado continua "loja".
 */
class RotuloDaLojaTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
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
        ], $attributes));
    }

    public function test_rotulo_do_canal_usa_o_nome_da_loja(): void
    {
        $this->assertSame('KazaKora', Order::rotuloDoCanal(Order::ORIGIN_STORE));
        $this->assertSame('KazaKora', Order::rotuloDoCanal(Order::ORIGIN_STORE, null));
        $this->assertSame('FerraKora', Order::rotuloDoCanal(Order::ORIGIN_STORE, Order::SITE_FERRAKORA));
        $this->assertSame('Beauty Kora', Order::rotuloDoCanal(Order::ORIGIN_STORE, Order::SITE_BEAUTYKORA));
        $this->assertSame('Shopee', Order::rotuloDoCanal(Order::ORIGIN_SHOPEE));
        // site_origem só vale para pedido do site.
        $this->assertSame('Shopee', Order::rotuloDoCanal(Order::ORIGIN_SHOPEE, Order::SITE_FERRAKORA));
    }

    public function test_clientes_mostram_beauty_kora_separado_da_kazakora(): void
    {
        $this->makeOrder(['buyer_document' => '12345678909']);
        $beauty = $this->makeOrder(['buyer_document' => '12345678909', 'site_origem' => Order::SITE_BEAUTYKORA]);
        $this->assertSame('loja', $beauty->fresh()->origin);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/clientes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.origins', ['loja'])
                ->where('customers.data.0.canais', fn ($canais) => collect($canais)->sort()->values()->all() === ['beautykora', 'loja']));

        $this->actingAs($admin)->get('/admin/clientes/12345678909')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('orders', fn ($orders) => collect($orders)->pluck('canal')->sort()->values()->all() === ['beautykora', 'loja']
                    && collect($orders)->pluck('origin')->unique()->values()->all() === ['loja']));
    }
}

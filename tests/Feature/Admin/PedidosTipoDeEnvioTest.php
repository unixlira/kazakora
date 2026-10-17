<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Coluna Envios da lista de pedidos do admin (pedido do Lira 2026-10-16):
 * no Mercado Livre, Flex separado de Mercado Envios, e cada canal com o
 * nome certo do envio. Os métodos usados aqui são os valores reais de
 * channel_shipments em produção nos últimos 60 dias (16/10/2026).
 */
class PedidosTipoDeEnvioTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $origin, ?string $metodo, array $extra = []): Order
    {
        $order = Order::create([
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

        if ($metodo !== null) {
            ChannelShipment::create([
                'order_id' => $order->id,
                'channel' => $origin,
                'shipping_method' => $metodo,
            ]);
        }

        return $order;
    }

    public function test_coluna_envios_mostra_o_tipo_de_cada_canal(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $esperado = [
            $this->pedido(Order::ORIGIN_MERCADO_LIVRE, 'self_service')->id => ['flex', 'Flex'],
            $this->pedido(Order::ORIGIN_MERCADO_LIVRE, 'xd_drop_off')->id => ['mercado_envios', 'Mercado Envios'],
            $this->pedido(Order::ORIGIN_MERCADO_LIVRE, 'drop_off')->id => ['mercado_envios', 'Mercado Envios'],
            $this->pedido(Order::ORIGIN_MERCADO_LIVRE, 'fulfillment')->id => ['full', 'Full'],
            $this->pedido(Order::ORIGIN_SHOPEE, 'Shopee Xpress')->id => ['express_coleta', null],
            $this->pedido(Order::ORIGIN_SHOPEE, 'Retirada pelo Comprador')->id => ['retirada', 'Retirada'],
            $this->pedido(Order::ORIGIN_TIKTOK_SHOP, 'LSV-Standard-BR PICKUP')->id => ['express_coleta', null],
            $this->pedido(Order::ORIGIN_AMAZON, 'Correios SEDEX (contrato)')->id => ['correios_sedex', 'Correios - SEDEX'],
            $this->pedido(Order::ORIGIN_AMAZON, null)->id => ['correios', 'Correios'],
            $this->pedido(Order::ORIGIN_STORE, null, ['shipping_carrier_name' => 'SEDEX (TURBO)'])->id => ['correios_sedex', 'Correios - SEDEX'],
            $this->pedido(Order::ORIGIN_STORE, null, ['shipping_carrier_name' => 'Frete Grátis'])->id => ['outro', 'Frete Grátis'],
        ];

        // Site com pré-postagem gerada: vale o serviço real da etiqueta.
        $site = $this->pedido(Order::ORIGIN_STORE, null, ['shipping_carrier_name' => 'Frete Grátis']);
        CorreiosPrePostagem::create([
            'order_id' => $site->id,
            'status' => CorreiosPrePostagem::STATUS_GERADA,
            'service_label' => 'PAC (contrato)',
            'service_code' => '03298',
            'customer_name' => 'Cliente',
            'zip' => '01000000',
            'street' => 'Rua X',
            'number' => '1',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'weight_grams' => 300,
            'content_items' => [],
        ]);
        $esperado[$site->id] = ['correios_pac', 'Correios - PAC'];

        $this->actingAs($admin)->get('/admin/pedidos')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($esperado) {
                $linhas = collect($page->toArray()['props']['orders']['data'])->keyBy('id');

                foreach ($esperado as $id => [$tipo, $curto]) {
                    $this->assertSame($tipo, $linhas[$id]['shipping_type'], "pedido {$id}");
                    if ($curto !== null) {
                        $this->assertSame($curto, $linhas[$id]['shipping_type_short'], "pedido {$id}");
                    }
                    $this->assertNotEmpty($linhas[$id]['shipping_type_label']);
                    $this->assertArrayNotHasKey('channel_shipment', $linhas[$id]);
                }
            });
    }
}

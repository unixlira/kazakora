<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Modules\Marketplace\Support\MelhorEnvioAutoShipping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Botão "Verificar etiqueta agora" numa venda do site (pedido do Lira 25/11/2026): a etiqueta é
 * comprada pela loja no Melhor Envio; se a compra falhou antes (sem saldo), o botão tenta comprar de
 * novo e não fala de Shopee/Mercado Livre.
 */
class VerificarEtiquetaSiteTest extends TestCase
{
    use RefreshDatabase;

    private function pedidoDoSite(): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente Teste', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua Teste', 'shipping_number' => '100', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 20, 'total' => 20,
        ]);
        ChannelShipment::query()->create(['order_id' => $order->id, 'channel' => Order::ORIGIN_STORE, 'status' => 'confirmed']);
        MelhorEnvioEnvio::query()->create(['order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_ERRO]);

        return $order;
    }

    public function test_venda_do_site_tenta_comprar_de_novo_e_mostra_o_erro_do_melhor_envio(): void
    {
        $order = $this->pedidoDoSite();
        $mock = Mockery::mock(MelhorEnvioAutoShipping::class)->makePartial();
        $mock->shouldReceive('confirm')->once()->andThrow(new RuntimeException('Compra da etiqueta no Melhor Envio recusada (confira o saldo da carteira).'));
        $this->app->instance(MelhorEnvioAutoShipping::class, $mock);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $resposta = $this->actingAs($admin)->from("/admin/pedidos/{$order->id}")->post("/admin/pedidos/{$order->id}/verificar-etiqueta");

        $resposta->assertRedirect();
        $erro = session('error');
        $this->assertStringContainsString('Melhor Envio', $erro);
        $this->assertStringNotContainsString('Shopee', $erro);
    }
}

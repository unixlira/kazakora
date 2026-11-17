<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Endereço alterado depois da compra (Lira, 2026-11-17, pedido #4246): o
 * histórico guarda o que a cliente digitou e o pedido guarda o original.
 */
class EnderecoDoPedidoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187-040',
            'shipping_street' => 'Rua Errada', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);
    }

    private function novo(array $extra = []): array
    {
        return array_merge([
            'shipping_zip' => '03187-040',
            'shipping_street' => 'Rua Certa',
            'shipping_number' => '10',
            'shipping_complement' => 'Apto 2',
            'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'sp',
            'motivo' => 'Cliente pediu no WhatsApp',
        ], $extra);
    }

    public function test_alterar_o_endereco_grava_o_digitado_e_o_novo_na_linha_do_tempo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Lira']);
        $order = $this->pedido();

        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}/endereco", $this->novo())
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('Rua Certa', $order->shipping_street);
        $this->assertSame('SP', $order->shipping_state);
        $this->assertSame('Rua Errada', $order->endereco_digitado['rua']);
        $this->assertSame('1', $order->endereco_digitado['numero']);

        $evento = OrderFulfillmentEvent::query()->where('order_id', $order->id)->where('step', OrderFulfillmentEvent::STEP_ADDRESS_CHANGED)->sole();
        $this->assertSame('address_changed', OrderFulfillmentEvent::STEP_ADDRESS_CHANGED);
        $this->assertSame(OrderFulfillmentEvent::STATUS_SUCCESS, $evento->status);
        foreach (['digitado_pela_cliente', 'corrigido_para', 'motivo', 'por'] as $chave) {
            $this->assertArrayHasKey($chave, $evento->context);
        }
        $this->assertSame('Rua Errada', $evento->context['digitado_pela_cliente']['rua']);
        $this->assertSame('Rua Certa', $evento->context['corrigido_para']['rua']);
        $this->assertSame('Apto 2', $evento->context['corrigido_para']['complemento']);
        $this->assertSame('Cliente pediu no WhatsApp', $evento->context['motivo']);
        $this->assertSame('Lira', $evento->context['por']);
        $this->assertStringContainsString('Rua Errada', (string) $evento->message);

        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('detalhe.endereco_digitado.rua', 'Rua Errada')
                ->etc());
    }

    public function test_segunda_alteracao_mantem_o_endereco_original(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->pedido();

        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}/endereco", $this->novo());
        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}/endereco", $this->novo(['shipping_number' => '20']));

        $order->refresh();
        $this->assertSame('20', $order->shipping_number);
        $this->assertSame('Rua Errada', $order->endereco_digitado['rua']);

        $eventos = OrderFulfillmentEvent::query()->where('order_id', $order->id)->where('step', OrderFulfillmentEvent::STEP_ADDRESS_CHANGED)->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame('Rua Errada', $eventos[1]->context['digitado_pela_cliente']['rua']);
        $this->assertSame('10', $eventos[1]->context['anterior']['numero']);
        $this->assertSame('20', $eventos[1]->context['corrigido_para']['numero']);
    }

    public function test_sem_mudanca_nao_grava_nada(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->pedido();

        $this->actingAs($admin)->patch("/admin/pedidos/{$order->id}/endereco", [
            'shipping_zip' => '03187-040', 'shipping_street' => 'Rua Errada', 'shipping_number' => '1',
            'shipping_neighborhood' => 'Mooca', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
        ])->assertSessionHas('success');

        $this->assertNull($order->fresh()->endereco_digitado);
        $this->assertDatabaseMissing('order_fulfillment_events', ['order_id' => $order->id, 'step' => OrderFulfillmentEvent::STEP_ADDRESS_CHANGED]);
    }

    public function test_cliente_nao_altera_endereco_pelo_admin(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->pedido();

        $this->actingAs($cliente)->patch("/admin/pedidos/{$order->id}/endereco", $this->novo())->assertForbidden();
        $this->assertSame('Rua Errada', $order->fresh()->shipping_street);
    }
}

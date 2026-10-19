<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Botão "Conferido" do KoraSync (2026-10-19): tira o pedido da fila de
 * separação e da aba Cancelados (e do contador do alerta) sem mexer em
 * status, packed_at ou etiqueta, e pode ser desfeito.
 */
class ConferidoKoraSyncTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-print-agent-token'];
    }

    private function makeOrder(array $attributes = []): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create(array_merge([
            'user_id' => $user->id,
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

    private function idsNaFila(): array
    {
        return collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/print-agent/dashboard/queue')
            ->assertOk()
            ->json('queue'))->pluck('id')->all();
    }

    public function test_route_requires_the_agent_token(): void
    {
        $order = $this->makeOrder();

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/conferido")->assertStatus(401);
        $this->assertNull($order->refresh()->conferido_em);
    }

    public function test_conferido_takes_a_paid_order_out_of_separation_without_touching_the_flow(): void
    {
        $order = $this->makeOrder();
        $outro = $this->makeOrder();

        $this->assertContains($order->id, $this->idsNaFila());
        $antes = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue')->json('pending_separation_count');

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/conferido", ['por' => 'Galpão'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('result', 'ok')
            ->assertJsonPath('conferido_por', 'Galpão');

        $order->refresh();
        $this->assertNotNull($order->conferido_em);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNull($order->packed_at);

        $ids = $this->idsNaFila();
        $this->assertNotContains($order->id, $ids);
        $this->assertContains($outro->id, $ids);
        $depois = $this->withHeaders($this->authHeaders())->getJson('/api/print-agent/dashboard/queue')->json('pending_separation_count');
        $this->assertSame($antes - 1, $depois);
    }

    public function test_conferido_takes_a_cancelled_order_out_of_the_cancelled_tab(): void
    {
        $cancelado = $this->makeOrder(['status' => Order::STATUS_CANCELLED]);

        $this->assertContains($cancelado->id, $this->idsNaFila());

        $this->postJson("/api/print-agent/dashboard/queue/{$cancelado->id}/conferido", [], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('conferido_por', 'KoraSync');

        $this->assertNotContains($cancelado->id, $this->idsNaFila());
        $this->assertSame(Order::STATUS_CANCELLED, $cancelado->refresh()->status);
    }

    public function test_conferido_is_idempotent_and_can_be_undone(): void
    {
        $order = $this->makeOrder();

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/conferido", [], $this->authHeaders())->assertOk();
        $primeira = $order->refresh()->conferido_em;
        $this->travel(5)->minutes();
        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/conferido", [], $this->authHeaders())->assertOk();
        $this->assertTrue($primeira->equalTo($order->refresh()->conferido_em));

        $this->postJson("/api/print-agent/dashboard/queue/{$order->id}/desfazer-conferido", [], $this->authHeaders())->assertOk();
        $this->assertNull($order->refresh()->conferido_em);
        $this->assertNull($order->conferido_por);
        $this->assertContains($order->id, $this->idsNaFila());
    }
}

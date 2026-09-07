<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Gerar etiquetas em lote" (2026-09-07) — o botão que virou o começo do
 * dia no galpão depois da inversão do fluxo: imprime tudo que dá, e só
 * depois o operador vai dando baixa com os papéis na mão.
 */
class BatchLabelPrintTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-print-agent-token'];
    }

    private function makeOrder(string $origin, ?string $labelPath = 'labels/etiqueta.pdf', ?string $packedAt = null): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => $origin,
            'external_order_id' => (string) fake()->numerify('#########'),
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
        ]);

        if ($packedAt) {
            $order->forceFill(['packed_at' => $packedAt])->save();
        }

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => $origin,
            'external_shipment_id' => 'SHIP-'.$order->id,
            'shipping_method' => 'self_service',
            'status' => $labelPath ? ChannelShipment::STATUS_LABEL_READY : ChannelShipment::STATUS_PENDING,
            'confirmed_at' => now(),
            'label_path' => $labelPath,
            'label_ready_at' => $labelPath ? now() : null,
        ]);

        return $order;
    }

    /**
     * O caso central: pedido pago, ainda NÃO separado, etiqueta baixada.
     * Antes de 2026-09-07 isso não imprimia nada (faltava o packed_at) — é
     * exatamente a trava que o lote existe pra furar, porque quem clicou
     * foi uma pessoa.
     */
    public function test_it_prints_every_available_label_of_orders_still_waiting_for_separation(): void
    {
        Queue::fake();

        $ml = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);
        $shopee = $this->makeOrder(Order::ORIGIN_SHOPEE);

        $this->postJson('/api/print-agent/dashboard/etiquetas/lote', [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['result' => 'ok', 'enfileiradas' => 2]);

        foreach ([$ml, $shopee] as $order) {
            $this->assertDatabaseHas('print_jobs', [
                'order_id' => $order->id,
                'status' => PrintJob::STATUS_QUEUED,
            ]);
        }
    }

    /** Pedido já separado não entra: o lote é a fila do que FALTA separar. */
    public function test_it_skips_orders_that_were_already_separated(): void
    {
        Queue::fake();

        $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE, packedAt: now()->toDateTimeString());

        $this->postJson('/api/print-agent/dashboard/etiquetas/lote', [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['enfileiradas' => 0, 'total_candidatos' => 0]);

        $this->assertDatabaseCount('print_jobs', 0);
    }

    /**
     * A regra do duplicado de 2026-09-07 vale dentro do lote também: papel
     * que já saiu não sai de novo, e o pedido aparece no resumo com a data
     * pra ninguém achar que ele foi esquecido.
     */
    public function test_it_never_reprints_a_label_that_already_came_out(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE);
        PrintJob::create([
            'order_id' => $order->id,
            'channel' => Order::ORIGIN_MERCADO_LIVRE,
            'label_path' => 'labels/etiqueta.pdf',
            'status' => PrintJob::STATUS_PRINTED,
            'printed_at' => now()->subDays(3),
        ]);

        $response = $this->postJson('/api/print-agent/dashboard/etiquetas/lote', [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['enfileiradas' => 0, 'ja_impressas' => 1]);

        $this->assertSame(1, PrintJob::where('order_id', $order->id)->count());
        $this->assertNotNull($response->json('detalhe.ja_impressas.0.impressa_em'));
    }

    /**
     * TikTok e Shein nunca saem pela nossa impressora — a trava do canal
     * vale aqui como vale em todo lugar que cria PrintJob.
     */
    public function test_it_never_prints_tiktok_or_shein(): void
    {
        Queue::fake();

        $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        $this->postJson('/api/print-agent/dashboard/etiquetas/lote', [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['enfileiradas' => 0, 'total_candidatos' => 0]);

        $this->assertDatabaseCount('print_jobs', 0);
    }

    /**
     * Pedido cuja etiqueta o canal ainda não liberou não some calado: entra
     * no resumo e leva um empurrão pra etiqueta chegar até o próximo lote.
     */
    public function test_it_reports_and_nudges_orders_without_a_label_yet(): void
    {
        Queue::fake();

        $order = $this->makeOrder(Order::ORIGIN_MERCADO_LIVRE, labelPath: null);

        $this->postJson('/api/print-agent/dashboard/etiquetas/lote', [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['enfileiradas' => 0, 'sem_etiqueta' => 1]);

        $this->assertDatabaseCount('print_jobs', 0);
        Queue::assertPushed(CheckShipmentLabelJob::class);
    }

    public function test_it_rejects_requests_without_a_valid_token(): void
    {
        $this->postJson('/api/print-agent/dashboard/etiquetas/lote')->assertStatus(401);
    }
}

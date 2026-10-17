<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Coluna "Nota" da lista de pedidos do admin (Lira 2026-10-16): só a nota
 * fiscal, com status, série/número e motivo da rejeição. O e-mail saiu da
 * listagem.
 */
class PedidosColunaNotaTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(): Order
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
        ]);
    }

    public function test_lista_traz_status_numero_e_motivo_da_nota_sem_email(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $autorizada = $this->pedido();
        Invoice::create([
            'order_id' => $autorizada->id,
            'origem' => Invoice::ORIGEM_PEDIDO,
            'ambiente' => Invoice::AMBIENTE_PRODUCAO,
            'status' => Invoice::STATUS_AUTHORIZED,
            'serie' => 2,
            'numero' => 1761,
        ]);

        $erro = $this->pedido();
        Invoice::create([
            'order_id' => $erro->id,
            'origem' => Invoice::ORIGEM_PEDIDO,
            'ambiente' => Invoice::AMBIENTE_PRODUCAO,
            'status' => Invoice::STATUS_ERROR,
            'serie' => 2,
            'numero' => 1800,
            'motivo_rejeicao' => 'Rejeição 539: duplicidade de NF-e',
        ]);

        $semNota = $this->pedido();

        $this->actingAs($admin)->get('/admin/pedidos')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($autorizada, $erro, $semNota) {
                $linhas = collect($page->toArray()['props']['orders']['data'])->keyBy('id');

                $this->assertSame('authorized', $linhas[$autorizada->id]['invoice']['status']);
                $this->assertSame(2, $linhas[$autorizada->id]['invoice']['serie']);
                $this->assertSame(1761, $linhas[$autorizada->id]['invoice']['numero']);

                $this->assertSame('error', $linhas[$erro->id]['invoice']['status']);
                $this->assertSame('Rejeição 539: duplicidade de NF-e', $linhas[$erro->id]['invoice']['motivo_rejeicao']);
                $this->assertSame(1800, $linhas[$erro->id]['invoice']['numero']);

                $this->assertNull($linhas[$semNota->id]['invoice']);

                foreach ($linhas as $linha) {
                    $this->assertArrayNotHasKey('latest_email_log', $linha);
                }
            });
    }
}

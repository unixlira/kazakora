<?php

namespace Tests\Feature\Api;

use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2026-11-12 (#4108): amostra grátis do TikTok entrava como venda de R$ 667,50 no KoraSync. */
class PedidoDeAmostraForaDasVendasTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(float $valor, bool $amostra = false): Order
    {
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_TIKTOK_SHOP, 'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01001000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP',
            'shipping_state' => 'SP', 'subtotal' => $valor, 'total' => $valor,
        ]);

        if ($amostra) {
            $pedido->forceFill(['amostra_em' => now()])->save();
        }

        return $pedido;
    }

    public function test_amostra_nao_conta_nas_vendas_nem_no_faturamento_do_korasync(): void
    {
        config(['services.print_agent.token' => 'agente']);
        $this->pedido(100.00);
        $this->pedido(667.50, amostra: true);

        $metricas = $this->withHeader('Authorization', 'Bearer agente')
            ->getJson('/api/print-agent/dashboard/metrics')
            ->assertOk()
            ->assertJsonPath('sales_today', 1)
            ->json();

        $this->assertEquals(100.00, $metricas['revenue_today']);
        $this->assertEquals(100.00, $metricas['revenue_month']);

        $canais = $this->withHeader('Authorization', 'Bearer agente')
            ->getJson('/api/print-agent/dashboard/channels')
            ->assertOk()
            ->json();

        $tiktok = collect($canais['channels'] ?? $canais)->firstWhere('channel', Order::ORIGIN_TIKTOK_SHOP);
        $this->assertNotNull($tiktok);
        $this->assertEquals(100.00, $tiktok['revenue_today']);
        $this->assertEquals(100.00, $tiktok['revenue_month']);
    }

    public function test_venda_efetiva_tira_a_amostra_e_desmarcar_devolve(): void
    {
        $amostra = $this->pedido(667.50, amostra: true);

        $this->assertSame(0, Order::query()->vendaEfetiva()->count());
        $this->assertTrue($amostra->fresh()->ehAmostra());

        $amostra->forceFill(['amostra_em' => null])->save();

        $this->assertSame(1, Order::query()->vendaEfetiva()->count());
    }
}

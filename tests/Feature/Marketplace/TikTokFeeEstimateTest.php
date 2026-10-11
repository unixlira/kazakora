<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\TikTokFeeEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pedido do usuário 2026-10-06: taxa estimada do TikTok (o Bling não traz
 * a comissão) calibrada no extrato real, trocada pelo real quando ele
 * chega, sem nunca tocar taxa real.
 */
class TikTokFeeEstimateTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(array $itens, string $externo = 'TT1'): Order
    {
        $subtotal = array_sum(array_map(fn ($i) => $i[0] * $i[1], $itens));
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_TIKTOK_SHOP, 'external_order_id' => $externo,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => $subtotal, 'total' => $subtotal,
        ]);

        foreach ($itens as [$quantidade, $preco]) {
            $order->items()->create(['product_name' => 'Item', 'product_price' => $preco, 'quantity' => $quantidade, 'subtotal' => $quantidade * $preco]);
        }

        return $order;
    }

    /** 25 pedidos de R$ 100 no extrato: afiliado 5%, comissão 0, frete R$ 8. */
    private function extrato(int $pedidos = 25): void
    {
        for ($i = 0; $i < $pedidos; $i++) {
            DB::table('marketplace_settlement_details')->insert([
                'channel' => 'tiktok_shop', 'transaction_type' => 'Pedido', 'external_order_id' => "EXT{$i}",
                'quantity' => 1, 'product_net_sales' => 100, 'affiliate_commissions' => -5, 'platform_commission_fee' => 0,
                'net_shipping_cost' => -8, 'order_created_at' => now()->subDays(10)->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_service_fee_follows_the_real_rule_and_averages_come_from_the_statement(): void
    {
        $this->extrato();
        // 2 x R$ 30 (fixo 4 cada) + 1 x R$ 60 (fixo 6): 4*2 + 6 + 6% de 120 = 21,20
        $estimativa = app(TikTokFeeEstimator::class)->estimate($this->pedido([[2, 30], [1, 60]]));

        $this->assertSame(21.2, $estimativa['service_fee']);
        $this->assertSame(8.0, $estimativa['shipping_fee']);
        $this->assertSame(6.0, $estimativa['breakdown']['afiliado_esperado']);
        $this->assertSame(35.2, $estimativa['fee_amount']);
        $this->assertSame(84.8, $estimativa['payout_amount']);
    }

    public function test_without_enough_statement_it_uses_the_calibrated_defaults(): void
    {
        $this->extrato(3);
        $medias = app(TikTokFeeEstimator::class)->medias();

        $this->assertSame(7.17, $medias['frete_por_pedido']);
        $this->assertSame(0, $medias['pedidos']);
    }

    public function test_command_estimates_never_touches_real_fees_and_drops_estimate_once_settled(): void
    {
        $this->extrato();
        $semTaxa = $this->pedido([[1, 50]], 'NOVO');
        $comTaxaReal = $this->pedido([[1, 50]], 'REAL');
        OrderChannelFee::create(['order_id' => $comTaxaReal->id, 'channel' => 'tiktok_shop', 'gross_amount' => 50, 'fee_amount' => 9.99, 'source' => OrderChannelFee::SOURCE_REPORT, 'computed_at' => now()]);
        $liquidado = $this->pedido([[1, 50]], 'EXT0');
        OrderChannelFee::create(['order_id' => $liquidado->id, 'channel' => 'tiktok_shop', 'gross_amount' => 50, 'fee_amount' => 20, 'source' => OrderChannelFee::SOURCE_ESTIMATE, 'computed_at' => now()]);

        $this->artisan('orders:estimar-taxas-tiktok')->assertSuccessful();

        $this->assertSame(OrderChannelFee::SOURCE_ESTIMATE, $semTaxa->channelFee()->first()->source);
        $this->assertSame('9.99', $comTaxaReal->channelFee()->first()->fee_amount);
        $this->assertNull($liquidado->channelFee()->first(), 'No extrato, vale o extrato.');
    }
}

<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos do TikTok Shop ainda não liquidados, com os valores que o próprio
 * TikTok prevê (Finanças › Em espera, api/oec/pay/merchant/statement/view/
 * onhold_orders) — pedido do Lira 04/11/2026: "trazer dados reais das vendas
 * do TikTok". Até o pedido liquidar, a margem usava a NOSSA estimativa
 * (receita = o que o cliente pagou, sem o desconto que o TikTok repõe; taxa
 * por média): nos 85 pedidos em espera de 04/11 isso dava R$ 4.002,46 contra
 * R$ 6.531,87 que o TikTok vai pagar.
 *
 * Grava em order_channel_fees com source "previsto": receita = vendas
 * líquidas previstas (ContributionMargin::receitaSql), taxa = vendas − valor a
 * receber (taxas + frete previstos). Nunca toca taxa real (extrato, API, à
 * mão); o extrato sobrescreve quando o pedido liquida.
 */
class EmEsperaTikTok
{
    private const VENDAS = 46;

    private const TAXAS = 51;

    private const FRETE = 53;

    /**
     * @param  list<array<string, mixed>>  $registros  order_records da tela Em espera
     * @return array{recebidos: int, gravados: int, sem_pedido: list<string>, ja_liquidados: int, taxa_real: int}
     */
    public function aplicar(array $registros): array
    {
        $resumo = ['recebidos' => count($registros), 'gravados' => 0, 'sem_pedido' => [], 'ja_liquidados' => 0, 'taxa_real' => 0];

        foreach ($registros as $r) {
            $id = trim((string) ($r['expression_order_id'] ?? $r['trade_order_id'] ?? ''));
            if ($id === '' || ! isset($r['total_onhold_net_amount']['amount'])) {
                continue;
            }

            $pedido = Order::query()->where('origin', Order::ORIGIN_TIKTOK_SHOP)->where('external_order_id', $id)->with('channelFee')->first();
            if (! $pedido) {
                $resumo['sem_pedido'][] = $id;

                continue;
            }

            if (DB::table('marketplace_settlement_details')->where('channel', Order::ORIGIN_TIKTOK_SHOP)
                ->where('transaction_type', 'Pedido')->where('external_order_id', $id)->exists()) {
                $resumo['ja_liquidados']++;

                continue;
            }

            $atual = $pedido->channelFee?->source;
            if ($atual !== null && ! in_array($atual, [OrderChannelFee::SOURCE_ESTIMATE, OrderChannelFee::SOURCE_PREVISTO], true)) {
                $resumo['taxa_real']++;

                continue;
            }

            $item = fn (int $codigo) => round((float) (collect($r['simple_breakdown'] ?? [])->firstWhere('item_id', $codigo)['amount']['amount'] ?? 0), 2);
            $vendas = $item(self::VENDAS);
            $receber = round((float) $r['total_onhold_net_amount']['amount'], 2);

            OrderChannelFee::query()->updateOrCreate(
                ['order_id' => $pedido->id, 'channel' => Order::ORIGIN_TIKTOK_SHOP],
                [
                    'gross_amount' => $vendas,
                    'fee_amount' => round($vendas - $receber, 2),
                    'commission_fee' => null,
                    'service_fee' => abs($item(self::TAXAS)),
                    'shipping_fee' => round(-$item(self::FRETE), 2),
                    'seller_discount' => null,
                    'platform_discount' => null,
                    'payout_amount' => $receber,
                    'breakdown' => null,
                    'source' => OrderChannelFee::SOURCE_PREVISTO,
                    'computed_at' => now(),
                ],
            );
            $resumo['gravados']++;
        }

        return $resumo;
    }
}

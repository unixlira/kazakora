<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\TikTokFeeEstimator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Grava a taxa estimada (TikTokFeeEstimator) nos pedidos TikTok que ainda
 * não têm taxa real, e atualiza as estimativas já gravadas — as médias do
 * extrato mudam conforme novos extratos entram. Nunca toca taxa real
 * (extrato importado, API, lançada à mão). Pedido que já aparece no
 * extrato perde a estimativa: quem vale ali é o extrato.
 *
 * Roda a cada 10 min (routes/console.php) — pedido novo do TikTok ganha
 * taxa estimada logo depois de importado.
 */
class EstimarTaxasTikTok extends Command
{
    protected $signature = 'orders:estimar-taxas-tiktok {--dias=60 : pedidos criados nos últimos N dias}';

    protected $description = 'Grava a taxa estimada do TikTok Shop nos pedidos sem taxa real';

    public function handle(TikTokFeeEstimator $estimator): int
    {
        $temExtrato = Schema::hasTable('marketplace_settlement_details');

        $pedidos = Order::query()->nonPurchaseReturn()
            ->where('origin', Order::ORIGIN_TIKTOK_SHOP)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->where(fn ($q) => $q
                ->whereDoesntHave('channelFee')
                ->orWhereHas('channelFee', fn ($taxa) => $taxa->where('source', OrderChannelFee::SOURCE_ESTIMATE)))
            ->with(['items', 'channelFee'])
            ->get();

        $gravados = 0;
        $removidos = 0;

        foreach ($pedidos as $pedido) {
            $liquidado = $temExtrato && $pedido->external_order_id && \Illuminate\Support\Facades\DB::table('marketplace_settlement_details')
                ->where('channel', Order::ORIGIN_TIKTOK_SHOP)
                ->where('transaction_type', 'Pedido')
                ->where('external_order_id', $pedido->external_order_id)
                ->exists();

            if ($liquidado) {
                if ($pedido->channelFee?->source === OrderChannelFee::SOURCE_ESTIMATE) {
                    $pedido->channelFee->delete();
                    $removidos++;
                }

                continue;
            }

            $estimativa = $estimator->estimate($pedido);

            OrderChannelFee::query()->updateOrCreate(
                ['order_id' => $pedido->id, 'channel' => Order::ORIGIN_TIKTOK_SHOP],
                [
                    'gross_amount' => $pedido->subtotal,
                    'fee_amount' => $estimativa['fee_amount'],
                    ...array_intersect_key($estimativa, array_flip(OrderChannelFee::COMPONENTES)),
                    'source' => OrderChannelFee::SOURCE_ESTIMATE,
                    'computed_at' => now(),
                ],
            );

            $gravados++;
        }

        $medias = $estimator->medias();
        $this->info(sprintf(
            '%d pedido(s) com taxa estimada, %d estimativa(s) trocada(s) pelo extrato real. Base: %d pedidos do extrato (afiliado %.2f%%, frete R$ %.2f/pedido).',
            $gravados,
            $removidos,
            $medias['pedidos'],
            $medias['afiliado'] * 100,
            $medias['frete_por_pedido'],
        ));

        return self::SUCCESS;
    }
}

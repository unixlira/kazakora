<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Console\Command;

/**
 * Pedido explícito 2026-08-14, auditando o dashboard financeiro: a
 * integração de taxa real da Shopee (ShopeeDriver::resolveMarketplaceFee(),
 * via escrow) só entrou em produção em 2026-08-09 (ver OrderImportService::
 * createOrder()) — todo pedido pago ANTES disso nunca teve
 * OrderChannelFee gravado, inflando o "lucro líquido" mostrado no
 * dashboard sem ninguém perceber (a taxa da Shopee gira em ~15-20% da
 * venda). Confirmado ao vivo que o escrow continua consultável pra pedido
 * antigo já liquidado — roda uma vez, direto pelo canal (não estimativa),
 * pra fechar a lacuna com o dado real.
 *
 * Um-a-um com try/catch por pedido (não aborta o lote inteiro por causa
 * de 1 pedido sem escrow fechado ou com erro transitório de rede) — igual
 * ao padrão já usado em RefreshOrderContactInfo/SyncMercadoLivreOrders.
 */
class BackfillShopeeOrderFeesCommand extends Command
{
    protected $signature = 'orders:backfill-shopee-fees';

    protected $description = 'Busca a taxa real (comissão + serviço) na Shopee pra todo pedido pago que ainda não tem OrderChannelFee gravado';

    public function handle(MarketplaceDriverManager $manager): int
    {
        /** @var ShopeeDriver $driver */
        $driver = $manager->driver('shopee');

        $orders = Order::query()
            ->where('origin', 'shopee')
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->whereDoesntHave('channelFee')
            ->get();

        $this->info("{$orders->count()} pedido(s) Shopee sem taxa registrada.");

        $filled = 0;
        $stillUnavailable = 0;

        foreach ($orders as $order) {
            $fee = $driver->resolveMarketplaceFee($order->external_order_id);

            if ($fee === null) {
                $stillUnavailable++;

                continue;
            }

            OrderChannelFee::query()->updateOrCreate(
                ['order_id' => $order->id, 'channel' => 'shopee'],
                [
                    'gross_amount' => $order->total,
                    'fee_amount' => $fee,
                    'source' => OrderChannelFee::SOURCE_API,
                    'computed_at' => now(),
                ],
            );

            $filled++;
        }

        $this->info("{$filled} taxa(s) preenchida(s) com dado real da Shopee. {$stillUnavailable} ainda sem escrow fechado (tenta de novo depois).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Regrava a taxa real (com a quebra: comissão, serviço, frete da loja,
 * descontos, repasse) dos pedidos Shopee e Mercado Livre já importados —
 * pedido do usuário 2026-10-06 pra margem "oficial e fidedigna". Taxa
 * lançada à mão no Fluxo de Caixa nunca é tocada.
 *
 *   php artisan orders:recalcular-taxas                 (mês corrente)
 *   php artisan orders:recalcular-taxas --desde=2026-09-01 --canal=shopee
 *   php artisan orders:recalcular-taxas --seco          (só mostra)
 */
class RecalcularTaxasDosCanais extends Command
{
    protected $signature = 'orders:recalcular-taxas
        {--desde= : data inicial (padrão: início do mês)}
        {--canal=* : shopee e/ou mercado_livre (padrão: os dois)}
        {--seco : só mostra o que mudaria, sem gravar}';

    protected $description = 'Regrava a taxa real com a quebra (comissão, frete da loja, repasse) dos pedidos Shopee e Mercado Livre';

    private const CANAIS = [Order::ORIGIN_SHOPEE, Order::ORIGIN_MERCADO_LIVRE];

    public function handle(MarketplaceDriverManager $manager): int
    {
        $desde = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : Carbon::today()->startOfMonth();
        $canais = array_values(array_intersect(self::CANAIS, $this->option('canal') ?: self::CANAIS));
        $seco = (bool) $this->option('seco');

        $pedidos = Order::query()
            ->whereIn('origin', $canais)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->where('created_at', '>=', $desde)
            ->whereNotNull('external_order_id')
            ->with('channelFee')
            ->orderBy('id')
            ->get();

        $this->info("{$pedidos->count()} pedido(s) desde {$desde->format('d/m/Y')}".($seco ? ' — modo seco, nada é gravado' : ''));

        $gravados = 0;
        $semDado = 0;
        $manuais = 0;
        $antes = 0.0;
        $depois = 0.0;

        foreach ($pedidos as $pedido) {
            if ($pedido->channelFee?->source === OrderChannelFee::SOURCE_MANUAL) {
                $manuais++;

                continue;
            }

            try {
                $quebra = $pedido->origin === Order::ORIGIN_SHOPEE
                    ? $manager->driver(Order::ORIGIN_SHOPEE)->resolveFeeBreakdown($pedido->external_order_id, (float) $pedido->subtotal)
                    : $manager->driver(Order::ORIGIN_MERCADO_LIVRE)->feeBreakdownFor($pedido->external_order_id);
            } catch (Throwable $exception) {
                $this->warn("#{$pedido->id}: {$exception->getMessage()}");
                $quebra = null;
            }

            // Respeita a cota das APIs dos canais.
            usleep(300000);

            if (! $quebra) {
                $semDado++;

                continue;
            }

            $antes += (float) ($pedido->channelFee?->fee_amount ?? 0);
            $depois += (float) $quebra['fee_amount'];

            if (! $seco) {
                OrderChannelFee::query()->updateOrCreate(
                    ['order_id' => $pedido->id, 'channel' => $pedido->origin],
                    [
                        'gross_amount' => $pedido->subtotal,
                        'fee_amount' => $quebra['fee_amount'],
                        ...array_intersect_key($quebra, array_flip(OrderChannelFee::COMPONENTES)),
                        'source' => OrderChannelFee::SOURCE_API,
                        'computed_at' => now(),
                    ],
                );
            }

            $gravados++;
        }

        $this->info(sprintf(
            '%d com taxa real%s, %d sem dado do canal ainda, %d lançadas à mão (mantidas). Taxas: R$ %s antes → R$ %s agora.',
            $gravados,
            $seco ? ' (não gravadas)' : ' gravadas',
            $semDado,
            $manuais,
            number_format($antes, 2, ',', '.'),
            number_format($depois, 2, ',', '.'),
        ));

        return self::SUCCESS;
    }
}

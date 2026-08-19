<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Console\Command;

/**
 * BUG REAL descoberto 2026-08-19 ("produto embalando errado" no KoraSync,
 * quase causou envio errado — usuário flagrou a tempo): item de pedido
 * ML/Shopee sem produto local mapeado tentava `autoImportProduct()` uma
 * ÚNICA vez no import; se falhasse (API do canal fora do ar naquele
 * instante), ficava sem produto — e sem SKU nenhum — pra sempre, sem
 * nenhum jeito automático de tentar de novo. 38 itens de pedidos reais
 * acumulados assim, recuperados às cegas via grep no log de erro dessa vez
 * (ver migration add_external_item_id_to_order_items_table) — este comando
 * fecha essa lacuna de vez, rodando periodicamente (ver routes/console.php)
 * em vez de depender de alguém notar e caçar no log.
 *
 * Nunca cria vínculo às cegas quando o anúncio tem MAIS DE UMA variação e o
 * item não tem `external_model_id` salvo (só acontece pro estoque antigo,
 * anterior a essa migration — daqui pra frente o model_id sempre é salvo,
 * casado ou não) — achado real 2026-08-15 (Ring Light 8"/10", pedido #376):
 * linkar um external_id multi-variação sem saber QUAL variação foi
 * comprada repetiria exatamente o erro que gerou este incidente. Fica
 * marcado como "ambíguo" pra revisão manual em vez de arriscar.
 *
 * Nunca debita estoque aqui — o estoque "ao vivo" trazido do canal na hora
 * de criar o produto (`autoImportProduct(..., quantitySold: 0)`) já
 * reflete a venda que está sendo recuperada agora (o canal já debitou do
 * lado dele no momento real da venda, independente de quando conseguimos
 * mapear pro catálogo local) — debitar de novo aqui contaria a mesma
 * venda 2x.
 */
class RelinkUnmappedMarketplaceItems extends Command
{
    protected $signature = 'marketplace:relink-unmapped-items {--dry-run : Só lista o que seria vinculado, não grava nada}';

    protected $description = 'Tenta de novo vincular produto local pros itens de pedido ML/Shopee que ficaram sem mapear na importação.';

    public function handle(MarketplaceDriverManager $manager): int
    {
        $items = OrderItem::query()
            ->whereNull('product_id')
            ->whereNotNull('external_item_id')
            ->whereHas('order', fn ($query) => $query->whereIn('origin', [Order::ORIGIN_MERCADO_LIVRE, Order::ORIGIN_SHOPEE]))
            ->with('order:id,origin,external_order_id')
            ->get();

        if ($items->isEmpty()) {
            $this->info('Nenhum item pendente de vínculo.');

            return self::SUCCESS;
        }

        $groups = $items->groupBy(fn (OrderItem $item) => $item->order->origin.'|'.$item->external_item_id.'|'.($item->external_model_id ?? ''));

        $relinked = 0;
        $ambiguous = 0;
        $failed = 0;

        foreach ($groups as $key => $group) {
            [$channel, $externalId, $externalModelId] = array_pad(explode('|', $key, 3), 3, '');
            $externalModelId = $externalModelId !== '' ? $externalModelId : null;
            $orderRefs = $group->pluck('order.external_order_id')->implode(', ');

            $listingQuery = ProductChannelListing::query()->where('channel', $channel)->where('external_id', $externalId);
            $listing = $externalModelId
                ? (clone $listingQuery)->where('external_model_id', $externalModelId)->first()
                : (clone $listingQuery)->whereNull('external_model_id')->first();

            $product = $listing?->product;

            if (! $product && ! $externalModelId && (clone $listingQuery)->whereNotNull('external_model_id')->exists()) {
                $this->warn("AMBÍGUO — {$channel}/{$externalId} tem variação e este item não sabe qual (pedido antigo, sem model_id salvo): {$orderRefs}");
                $ambiguous += $group->count();

                continue;
            }

            if (! $product) {
                if ($this->option('dry-run')) {
                    $this->line("[dry-run] importaria {$channel}/{$externalId}".($externalModelId ? "/{$externalModelId}" : '')." — {$group->count()} pedido(s): {$orderRefs}");

                    continue;
                }

                $product = $manager->driver($channel)->autoImportProduct($externalId, 0, $externalModelId);
            }

            if (! $product) {
                $this->error("Falhou (canal recusou/não achou): {$channel}/{$externalId} — {$orderRefs}");
                $failed += $group->count();

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("[dry-run] vincularia #{$product->id} {$product->name} — {$group->count()} pedido(s): {$orderRefs}");

                continue;
            }

            foreach ($group as $item) {
                $item->update(['product_id' => $product->id, 'product_name' => $product->name]);
            }

            $relinked += $group->count();
            $this->line("Vinculado: {$channel}/{$externalId} -> #{$product->id} {$product->name} ({$group->count()} pedido(s): {$orderRefs})");
        }

        $this->info("Vinculados: {$relinked}. Ambíguos (revisar na mão): {$ambiguous}. Falharam: {$failed}.");

        return self::SUCCESS;
    }
}

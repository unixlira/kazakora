<?php

namespace App\Console\Commands;

use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Console\Command;

/**
 * Pedido explícito 2026-08-21: preenche/atualiza os dados fiscais
 * (NCM/CFOP/CSOSN/PIS-COFINS etc.) de TODO produto local vinculado a um
 * anúncio da Shopee, usando o `tax_info` que o vendedor já cadastrou lá —
 * mesma lógica testada de ShopeeDriver::importFiscalData(), já usada há
 * semanas em autoImportProduct() pra produto novo, só que aqui reaproveitada
 * pra produto JÁ existente no catálogo (o gatilho real: pedidos #390/#411
 * do Mercado Livre travados por causa de um produto sem NCM cadastrado —
 * ver InvoiceService — que também está anunciado na Shopee com o dado
 * fiscal completo lá).
 *
 * Sobrescreve o dado fiscal local sempre que a Shopee tiver NCM preenchido
 * (mesma regra de importFiscalData() — Shopee é a fonte de verdade desse
 * dado fiscal, já usado por vendas reais dela) — produto sem NCM na Shopee
 * fica de fora e some no relatório final, pra revisão manual.
 */
class SyncShopeeFiscalData extends Command
{
    protected $signature = 'shopee:sync-fiscal-data {--product= : Sincronizar só um product_id específico}';

    protected $description = 'Preenche os dados fiscais dos produtos vinculados à Shopee usando o tax_info de lá.';

    public function handle(ShopeeDriver $driver): int
    {
        $query = ProductChannelListing::query()
            ->with('product')
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '');

        if ($productId = $this->option('product')) {
            $query->where('product_id', (int) $productId);
        }

        $synced = [];
        $missingOnShopee = [];
        $lookupFailed = [];

        foreach ($query->get() as $listing) {
            $product = $listing->product;

            if (! $product) {
                continue;
            }

            $item = $driver->fetchItemDetail($listing->external_id);

            if (! $item) {
                $lookupFailed[] = "#{$product->id} {$product->name}";

                continue;
            }

            $hadFiscalData = ProductFiscalData::query()->where('product_id', $product->id)->whereNotNull('ncm')->exists();

            if ($driver->importFiscalData($product, $item['tax_info'] ?? null)) {
                $synced[] = sprintf('#%d %s%s', $product->id, $product->name, $hadFiscalData ? ' (atualizado)' : ' (preenchido)');
            } else {
                $missingOnShopee[] = "#{$product->id} {$product->name}";
            }
        }

        $this->info(sprintf('%d produto(s) sincronizado(s) com dado fiscal da Shopee.', count($synced)));
        foreach ($synced as $line) {
            $this->line("  - {$line}");
        }

        if ($missingOnShopee !== []) {
            $this->warn(sprintf('%d produto(s) SEM NCM cadastrado na Shopee (não deu pra preencher):', count($missingOnShopee)));
            foreach ($missingOnShopee as $line) {
                $this->line("  - {$line}");
            }
        }

        if ($lookupFailed !== []) {
            $this->warn(sprintf('%d produto(s) não retornaram da API da Shopee (anúncio removido/erro de rede):', count($lookupFailed)));
            foreach ($lookupFailed as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }
}

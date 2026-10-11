<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\ShopeeMediaImportService;
use Illuminate\Console\Command;

/**
 * Pedido explícito 2026-08-16: importa fotos e vídeo do anúncio da Shopee
 * pros produtos locais que ainda NÃO têm nenhuma foto/vídeo próprio — não
 * mexe em mais nenhum campo do produto, e não sobrescreve mídia já
 * existente (fotos/vídeo carregados manualmente pelo admin sempre têm
 * prioridade, esse comando só preenche o que está vazio). Comando manual
 * (`php artisan shopee:import-media`), não agendado — é uma importação de
 * catálogo, não algo que precise rodar de novo sozinho (diferente de
 * reviews:sync, que traz avaliação nova toda hora).
 *
 * Download/otimização de mídia em si vive em ShopeeMediaImportService
 * (extraído 2026-08-16 pra ser reaproveitado também por
 * ShopeeImportMissingProducts, que dá mídia a um produto recém-criado).
 */
class ImportShopeeProductMedia extends Command
{
    protected $signature = 'shopee:import-media {--product= : Importar só um product_id específico}';

    protected $description = 'Importa fotos e vídeo dos anúncios da Shopee pros produtos locais que ainda não têm mídia própria.';

    public function handle(ShopeeMediaImportService $mediaImporter): int
    {
        $query = ProductChannelListing::query()
            ->with('product')
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '');

        if ($productId = $this->option('product')) {
            $query->where('product_id', (int) $productId);
        }

        $imagesImported = 0;
        $videosImported = 0;
        $skipped = 0;

        foreach ($query->get() as $listing) {
            $product = $listing->product;

            if (! $product) {
                continue;
            }

            $result = $mediaImporter->importMissing($product, $listing->external_id);

            if (! $result['images'] && ! $result['video']) {
                $skipped++;

                continue;
            }

            $imagesImported += (int) $result['images'];
            $videosImported += (int) $result['video'];
        }

        $this->info("Fotos importadas em {$imagesImported} produto(s), vídeo importado em {$videosImported} produto(s), {$skipped} já tinham mídia própria (ignorados).");

        return self::SUCCESS;
    }
}

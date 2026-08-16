<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\ShopeeMediaImportService;
use Illuminate\Console\Command;

/**
 * Pedido explícito 2026-08-16 ("tem produto faltando na grade... coloca
 * nesses novos que não tem no kazakora mas tem na shopee"): cadastra no
 * catálogo local (já ATIVO, visível na grade — diferente de
 * ShopeeDriver::autoImportProduct(), que entra como rascunho quando é
 * disparado por uma venda inesperada) cada anúncio da Shopee que ainda não
 * tem product_channel_listings, com foto/vídeo já importados junto (ver
 * ShopeeMediaImportService).
 *
 * Duas formas de pular um anúncio, pedidas explicitamente:
 * 1. `--exclude` (trecho de título, repetível) — ex.: "kit dia dos pais".
 * 2. SKU duplicado — achado real 2026-08-16: a loja tem vários pares de
 *    anúncio com o MESMO `item_sku` cadastrado na Shopee (2 anúncios pro
 *    mesmo produto físico, prática comum de vendedor pra testar
 *    título/imagem). Quando um dos dois já está vinculado a um produto
 *    local, o outro é o mesmo produto — não deve virar um 2º cadastro. A
 *    checagem cobre também duplicidade DENTRO da própria execução (2
 *    anúncios novos com o mesmo SKU, nenhum ainda vinculado).
 */
class ShopeeImportMissingProducts extends Command
{
    protected $signature = 'shopee:import-missing-products
        {--exclude=* : Trecho de título pra pular (case-insensitive), pode repetir}
        {--dry-run : Só lista o que seria criado, não grava nada}';

    protected $description = 'Cadastra no catálogo (ativo) os anúncios da Shopee que ainda não têm produto vinculado, pulando duplicidade de SKU e títulos excluídos.';

    public function handle(ShopeeDriver $driver, ShopeeMediaImportService $mediaImporter): int
    {
        $items = $driver->fetchOwnItems();

        $linkedExternalIds = ProductChannelListing::query()
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->pluck('external_id')
            ->all();

        $unlinked = collect($items)->reject(fn (array $item) => in_array($item['external_id'], $linkedExternalIds, true))->values();

        if ($unlinked->isEmpty()) {
            $this->info('Nenhum anúncio novo pra cadastrar — todos já estão vinculados.');

            return self::SUCCESS;
        }

        // 1 chamada em lote pra SKU de TODOS os itens (linkados + não
        // linkados) — precisa dos linkados também pra saber se um anúncio
        // não-linkado é duplicata de um que já virou produto.
        $skuByExternalId = $driver->fetchItemSkus(collect($items)->pluck('external_id')->all());

        $linkedSkus = collect($linkedExternalIds)
            ->map(fn (string $id) => $skuByExternalId[$id] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $excludeTerms = array_filter(array_map(
            fn (string $term) => mb_strtolower(trim($term)),
            $this->option('exclude'),
        ));

        $created = 0;
        $skippedDuplicateSku = 0;
        $skippedExcluded = 0;
        $skippedNoData = 0;

        foreach ($unlinked as $item) {
            $nameLower = mb_strtolower($item['name']);

            $excluded = false;
            foreach ($excludeTerms as $term) {
                if ($term !== '' && str_contains($nameLower, $term)) {
                    $excluded = true;

                    break;
                }
            }

            if ($excluded) {
                $skippedExcluded++;

                continue;
            }

            $sku = $skuByExternalId[$item['external_id']] ?? null;

            if ($sku && in_array($sku, $linkedSkus, true)) {
                $skippedDuplicateSku++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("[dry-run] criaria: {$item['name']} ({$item['external_id']})".($sku ? " sku={$sku}" : ''));

                continue;
            }

            $product = $driver->autoImportProduct($item['external_id']);

            if (! $product) {
                $skippedNoData++;

                continue;
            }

            // autoImportProduct() entra como rascunho por padrão (fluxo de
            // venda inesperada, ver docblock da interface) — aqui é um
            // cadastro deliberado pra grade pública, já nasce ativo.
            $product->update(['is_active' => true]);

            $mediaImporter->importMissing($product, $item['external_id']);

            $this->line("Criado: #{$product->id} {$product->name}");
            $created++;

            // Marca o SKU como "já coberto" mesmo dentro desta execução —
            // evita criar um 2º produto se o par duplicado também estava
            // sem vínculo.
            if ($sku) {
                $linkedSkus[] = $sku;
            }
        }

        $this->info(sprintf(
            'Criados: %d. Ignorados — SKU duplicado: %d, título excluído: %d, sem dado suficiente: %d.',
            $created,
            $skippedDuplicateSku,
            $skippedExcluded,
            $skippedNoData,
        ));

        return self::SUCCESS;
    }
}

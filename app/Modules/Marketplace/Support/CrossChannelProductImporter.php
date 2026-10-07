<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Venda de um canal que não sabe cadastrar produto sozinho (TikTok Shop e
 * Amazon, que chegam pelo Bling) de um SKU que não existe no Kazakora.
 *
 * Pedido explícito do usuário em 2026-10-07: produto vendido que não temos
 * cadastrado tem que ser importado completo. O cadastro de produto no
 * Bling não serve de fonte (conferido ao vivo: sem foto, sem descrição,
 * sem NCM, preço errado) e esses canais não têm API de anúncio ligada.
 * Mas a loja usa o MESMO SKU em todo canal: procura esse SKU nos anúncios
 * do Mercado Livre e da Shopee e importa por lá, pelo autoImportProduct()
 * do canal que tiver — que já traz nome, preço e (Shopee) fiscal, e o
 * resto vem do ProductCompletionService.
 *
 * Só casa por SKU EXATO. Nunca por nome: é o caso das variações ambíguas
 * (TikTok/Amazon com 4 cores do mesmo power bank), em que escolher errado
 * é mandar a cor errada pro cliente — esse continua no "Vincular produto".
 */
class CrossChannelProductImporter
{
    /** Canais que conseguem dizer qual anúncio tem um SKU, na ordem de preferência. */
    private const FONTES = [MarketplaceAccount::CHANNEL_SHOPEE, MarketplaceAccount::CHANNEL_MERCADO_LIVRE];

    public function __construct(private readonly MarketplaceDriverManager $drivers) {}

    public function import(string $channel, string $sku): ?Product
    {
        $sku = trim($sku);

        // Id interno do Bling ("BLING-123") ou id numérico de anúncio não é SKU.
        if ($sku === '' || str_starts_with($sku, 'BLING-') || ctype_digit($sku) || in_array($channel, self::FONTES, true)) {
            return null;
        }

        // A busca na Shopee varre todos os anúncios — e o relink roda de 30
        // em 30 minutos sobre os itens que continuam sem produto. SKU que
        // não existe em canal nenhum não precisa ser procurado de novo toda vez.
        $naoAchou = "cross_channel_import.miss.{$sku}";

        if (Cache::has($naoAchou)) {
            return null;
        }

        foreach (self::FONTES as $fonte) {
            try {
                $driver = $this->drivers->driver($fonte);

                if (! $driver->isConfigured() || ! method_exists($driver, 'findItemIdBySku')) {
                    continue;
                }

                $itemId = $driver->findItemIdBySku($sku);

                // Quantidade 0: o estoque desse anúncio NÃO foi baixado por
                // esta venda (ela é de outro canal), não tem o que somar de volta.
                $product = $itemId ? $driver->autoImportProduct($itemId, 0) : null;
            } catch (Throwable $exception) {
                Log::warning('marketplace.cross_channel_import.source_failed', ['channel' => $channel, 'source' => $fonte, 'sku' => $sku, 'message' => $exception->getMessage()]);

                continue;
            }

            if (! $product) {
                continue;
            }

            try {
                ProductChannelListing::query()->firstOrCreate(
                    ['channel' => $channel, 'external_id' => $sku],
                    ['product_id' => $product->id, 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED, 'last_synced_at' => now()],
                );
            } catch (UniqueConstraintViolationException) {
                // Produto já tem listing neste canal com outro código — o
                // vínculo é só atalho, o produto certo já foi achado.
            }

            Log::info('marketplace.cross_channel_import.imported', ['channel' => $channel, 'source' => $fonte, 'sku' => $sku, 'product_id' => $product->id]);

            return $product;
        }

        Cache::put($naoAchou, true, now()->addHours(6));

        return null;
    }
}

<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pedido explícito 2026-08-21 ("estou vendo os SKU tudo certo nas
 * plataformas e esta criando novos no kazakora... se tiver produto
 * duplicado no kazakora pode deletar") — limpa os produtos duplicados que
 * o bug real corrigido no mesmo dia (ver ShopeeDriver/MercadoLivreDriver
 * ::autoImportProduct(), commit "Casa produto existente pelo SKU real do
 * canal antes de criar duplicado") já deixou de criar DAQUI pra frente,
 * mas que já existiam no catálogo ANTES do fix.
 *
 * Duas causas de duplicidade tratadas separadamente:
 *
 * 1) Duas variações (mesmo channel+external_id+external_model_id) que
 *    viraram DOIS produtos locais — corrida de retry na hora da criação
 *    (mesma classe de bug do commit 2026-08-17, Ring Light 8"/10", só que
 *    dessa vez as duas tentativas conflitantes chegaram a criar produto
 *    cada uma, em vez de uma falhar limpo). Canônico = o de MENOR id (mais
 *    antigo, mais chance de já ter pedido/venda associada).
 *
 * 2) Produto com SKU sintético (SHOPEE-{id}/ML-{id}) cujo canal JÁ tem
 *    (hoje) um SKU real cadastrado que corresponde a outro produto
 *    diferente já existente no catálogo — o usuário preencheu o SKU real
 *    no painel do canal depois que o produto sintético já tinha sido
 *    criado. Canônico = o produto com o SKU real.
 *
 * Em ambos os casos: reaponta ProductChannelListing e OrderItem pro
 * produto canônico, então soft-delete o duplicado. NUNCA soma/mexe no
 * estoque automaticamente — os dois lados às vezes têm estoque genuíno
 * (o canal debita local mesmo com produto "errado" vinculado), somar às
 * cegas pode inventar estoque que não existe fisicamente; fica registrado
 * no relatório pra revisão manual do usuário.
 */
class DedupeAutoImportedProducts extends Command
{
    protected $signature = 'marketplace:dedupe-products {--apply : Aplica de verdade — sem essa flag só mostra o que faria}';

    protected $description = 'Detecta e funde produtos duplicados criados por autoImportProduct() antes do fix de SKU real.';

    public function handle(MarketplaceDriverManager $manager): int
    {
        $apply = (bool) $this->option('apply');

        $products = Product::query()
            ->where(fn ($q) => $q->where('sku', 'like', 'SHOPEE-%')->orWhere('sku', 'like', 'ML-%'))
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            $this->info('Nenhum produto com SKU sintético encontrado.');

            return self::SUCCESS;
        }

        $merges = []; // duplicateId => canonicalId
        $noSkuOnChannel = [];

        // --- Causa 1: mesmo listing (channel+external_id+model_id) mapeado
        // pra mais de um produto sintético. ---
        $byListingKey = [];

        foreach ($products as $product) {
            foreach (ProductChannelListing::where('product_id', $product->id)->get() as $listing) {
                $key = $listing->channel.'|'.$listing->external_id.'|'.($listing->external_model_id ?? '');
                $byListingKey[$key][] = $product->id;
            }
        }

        foreach ($byListingKey as $key => $productIds) {
            $productIds = array_unique($productIds);

            if (count($productIds) < 2) {
                continue;
            }

            sort($productIds);
            $canonicalId = $productIds[0];

            foreach (array_slice($productIds, 1) as $dupId) {
                $merges[$dupId] = $canonicalId;
            }
        }

        // --- Causa 2: produto sintético cujo canal já tem SKU real
        // cadastrado, e esse SKU real já pertence a outro produto. ---
        foreach ($products as $product) {
            if (isset($merges[$product->id])) {
                continue; // já resolvido pela causa 1
            }

            $listing = ProductChannelListing::where('product_id', $product->id)->first();

            if (! $listing) {
                continue;
            }

            $item = $manager->driver($listing->channel)->fetchItemDetail($listing->external_id);
            $realSku = $item['sku'] ?? null;

            if (! $realSku) {
                $noSkuOnChannel[] = $product;

                continue;
            }

            $canonical = Product::where('sku', $realSku)->first();

            if ($canonical && $canonical->id !== $product->id) {
                $merges[$product->id] = $canonical->id;
            }
        }

        if ($merges === []) {
            $this->info('Nenhuma duplicidade encontrada entre os '.$products->count().' produto(s) com SKU sintético.');
        }

        // Resolve cadeia (ex: #98 funde em #94, que por sua vez funde em
        // #19 — real, achado nesta mesma varredura) pro id canônico FINAL
        // antes de aplicar qualquer coisa — sem isso, a ordem de iteração
        // do array decidiria se a fusão em cadeia funciona (processar
        // #94->#19 antes de #98->#94 deixaria #98 apontando pra um produto
        // já soft-deletado, já que Product::find() não vê trashed).
        foreach ($merges as $dupId => $canonicalId) {
            $seen = [$dupId => true];

            while (isset($merges[$canonicalId])) {
                if (isset($seen[$canonicalId])) {
                    break; // ciclo real (não deveria acontecer) — para de seguir.
                }

                $seen[$canonicalId] = true;
                $canonicalId = $merges[$canonicalId];
            }

            $merges[$dupId] = $canonicalId;
        }

        foreach ($merges as $dupId => $canonicalId) {
            $dup = $products->get($dupId) ?? Product::find($dupId);
            $canonical = Product::find($canonicalId);

            if (! $dup || ! $canonical) {
                continue;
            }

            $orderCount = OrderItem::where('product_id', $dup->id)->count();

            $this->line(sprintf(
                '#%d "%s" (sku=%s, estoque=%d, %d pedido(s)) -> fundir em #%d "%s" (sku=%s, estoque=%d)',
                $dup->id,
                $dup->name,
                $dup->sku,
                $dup->stock,
                $orderCount,
                $canonical->id,
                $canonical->name,
                $canonical->sku,
                $canonical->stock,
            ));

            if ($dup->stock > 0) {
                $this->warn("  atenção: #{$dup->id} tem estoque {$dup->stock} — NÃO somado automaticamente no #{$canonical->id}, revisar na mão.");
            }

            if ($apply) {
                DB::transaction(function () use ($dup, $canonical) {
                    ProductChannelListing::where('product_id', $dup->id)->update(['product_id' => $canonical->id]);
                    OrderItem::where('product_id', $dup->id)->update(['product_id' => $canonical->id, 'product_name' => $canonical->name]);
                    $dup->delete();
                });
            }
        }

        if ($noSkuOnChannel !== []) {
            $this->warn(count($noSkuOnChannel).' produto(s) com SKU sintético cujo canal ainda NÃO tem SKU real cadastrado (não dá pra confirmar se é duplicado):');
            foreach ($noSkuOnChannel as $p) {
                $this->line("  - #{$p->id} {$p->sku} {$p->name}");
            }
        }

        $this->info($apply
            ? count($merges).' duplicidade(s) fundida(s) de verdade.'
            : count($merges).' duplicidade(s) encontrada(s) — rode com --apply pra fundir de verdade.');

        return self::SUCCESS;
    }
}

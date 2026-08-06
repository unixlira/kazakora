<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Support\Str;

/**
 * Vincula anúncios já publicados na Shopee (feitos direto por lá, fora do
 * Kazakora — achado real 2026-08-06, a loja tinha produtos ativos na Shopee
 * nunca trazidos pro catálogo local) a produtos locais existentes, por
 * similaridade de nome. Decisão explícita do usuário: vínculo automático,
 * sem tela de revisão manual — mas só quando a confiança é alta o
 * suficiente (ver LINK_THRESHOLD), pra não arriscar vincular o item errado
 * só pra "preencher" tudo.
 *
 * Comparação por sobreposição de palavras (Jaccard), não por string bruta
 * (similar_text/levenshtein) — títulos da Shopee e do catálogo local
 * descrevem o mesmo produto com palavras em ordem/composição diferentes
 * (ex.: "Tábua Mágica para Descongelar Carne e Alimentos Rápido" vs "Tabua
 * De Descongelar Rápido Carne Alimentos Defrost Express Preto"), então
 * comparação por caractere penaliza demais a reordenação.
 */
class ShopeeProductImportService
{
    private const LINK_THRESHOLD = 0.35;

    /** Palavras genéricas demais pra pesarem na comparação. */
    private const STOPWORDS = [
        'de', 'da', 'do', 'das', 'dos', 'com', 'para', 'e', 'em', 'a', 'o',
        'os', 'as', 'um', 'uma', 'no', 'na', 'kit', 'original',
    ];

    public function __construct(private readonly ShopeeDriver $driver) {}

    /**
     * @return array{linked: int, already_linked: int, unmatched: array<int, string>}
     */
    public function import(): array
    {
        $items = $this->driver->fetchOwnItems();

        $alreadyLinkedIds = ProductChannelListing::query()
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->pluck('external_id')
            ->all();

        $candidateProducts = Product::query()->where('is_active', true)->get(['id', 'name']);
        $localTokens = $candidateProducts->mapWithKeys(fn (Product $product) => [
            $product->id => $this->tokenize($product->name),
        ]);

        // product_channel_listings tem unique(product_id, channel) — um
        // produto local só pode ter 1 vínculo por canal. Rastreia os já
        // reivindicados (existentes + os que este próprio import for
        // criando) pra não tentar vincular 2 itens Shopee diferentes ao
        // mesmo produto local (bug real encontrado 2026-08-06: 2 anúncios
        // — provavelmente variações de cor sem produto local separado —
        // bateram no mesmo produto e o segundo violou a constraint).
        $claimedProductIds = ProductChannelListing::query()
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->pluck('product_id')
            ->flip()
            ->all();

        $linked = 0;
        $alreadyLinked = 0;
        $unmatched = [];

        foreach ($items as $item) {
            if (in_array($item['external_id'], $alreadyLinkedIds, true)) {
                $alreadyLinked++;

                continue;
            }

            $itemTokens = $this->tokenize($item['name']);
            $bestProductId = null;
            $bestScore = 0.0;

            foreach ($localTokens as $productId => $tokens) {
                if (isset($claimedProductIds[$productId])) {
                    continue;
                }

                $score = $this->jaccard($itemTokens, $tokens);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestProductId = $productId;
                }
            }

            if ($bestProductId !== null && $bestScore >= self::LINK_THRESHOLD) {
                ProductChannelListing::query()->create([
                    'product_id' => $bestProductId,
                    'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
                    'is_enabled' => true,
                    'status' => ProductChannelListing::STATUS_PUBLISHED,
                    'external_id' => $item['external_id'],
                    'last_synced_at' => now(),
                ]);

                $claimedProductIds[$bestProductId] = true;
                $linked++;
            } else {
                $unmatched[] = $item['name'];
            }
        }

        return [
            'linked' => $linked,
            'already_linked' => $alreadyLinked,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $ascii = Str::of($text)->ascii()->lower()->toString();
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff($words, self::STOPWORDS));
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if (! $a || ! $b) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}

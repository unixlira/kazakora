<?php

namespace App\Services\MercadoLivre\Services;

use App\Modules\Catalog\Models\Product;
use App\Services\MercadoLivre\MercadoLivrePublicClient;

class CompetitorAnalysisService
{
    /** Preferred order when a category offers more than one listing type. */
    private const LISTING_TYPE_PRIORITY = ['gold_special', 'gold_pro', 'gold_premium'];

    public function __construct(private readonly MercadoLivrePublicClient $client) {}

    /**
     * @return array{query: string, results: array<int, array<string, mixed>>}
     */
    public function search(Product $product): array
    {
        $siteId = config('mercadolivre.site_id');
        $query = trim($product->name.' '.($product->category?->name ?? ''));

        $response = $this->client->get("sites/{$siteId}/search", [
            'q' => $query,
            'limit' => 50,
        ]);

        $results = array_map(fn (array $item) => [
            'ml_item_id' => $item['id'] ?? null,
            'title' => $item['title'] ?? '',
            'price' => (float) ($item['price'] ?? 0),
            'permalink' => $item['permalink'] ?? null,
            'seller_nickname' => $item['seller']['nickname'] ?? null,
            'seller_id' => $item['seller']['id'] ?? null,
            'condition' => $item['condition'] ?? null,
            'thumbnail' => $item['thumbnail'] ?? null,
            'category_id' => $item['category_id'] ?? null,
            'estimated_fee_amount' => null,
            'estimated_fee_percentage' => null,
            'estimated_fee_listing_type' => null,
        ], $response['results'] ?? []);

        $results = $this->attachEstimatedFees($results, $siteId, (float) $product->final_price);

        return ['query' => $query, 'results' => $results];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function attachEstimatedFees(array $results, string $siteId, float $referencePrice): array
    {
        $categoryIds = array_values(array_unique(array_filter(array_column($results, 'category_id'))));
        $categoryIds = array_slice($categoryIds, 0, 5);

        $feesByCategory = [];

        foreach ($categoryIds as $categoryId) {
            $feesByCategory[$categoryId] = $this->estimateFeeForCategory($siteId, $categoryId, $referencePrice);
        }

        return array_map(function (array $result) use ($feesByCategory) {
            $fee = $feesByCategory[$result['category_id']] ?? null;

            if ($fee) {
                $result['estimated_fee_amount'] = $fee['amount'];
                $result['estimated_fee_percentage'] = $fee['percentage'];
                $result['estimated_fee_listing_type'] = $fee['listing_type'];
            }

            unset($result['category_id']);

            return $result;
        }, $results);
    }

    /**
     * @return array{amount: float, percentage: ?float, listing_type: string}|null
     */
    private function estimateFeeForCategory(string $siteId, string $categoryId, float $referencePrice): ?array
    {
        $options = $this->client->get("sites/{$siteId}/listing_prices", [
            'price' => $referencePrice,
            'category_id' => $categoryId,
        ]);

        if (! is_array($options) || $options === []) {
            return null;
        }

        $byType = [];
        foreach ($options as $option) {
            if (isset($option['listing_type_id'])) {
                $byType[$option['listing_type_id']] = $option;
            }
        }

        $chosen = null;
        foreach (self::LISTING_TYPE_PRIORITY as $type) {
            if (isset($byType[$type])) {
                $chosen = $byType[$type];
                break;
            }
        }
        $chosen ??= $options[0] ?? null;

        if (! $chosen) {
            return null;
        }

        return [
            'amount' => (float) ($chosen['sale_fee_amount'] ?? 0),
            'percentage' => isset($chosen['sale_fee_details']['percentage_fee'])
                ? (float) $chosen['sale_fee_details']['percentage_fee']
                : null,
            'listing_type' => $chosen['listing_type_id'] ?? 'gold_special',
        ];
    }
}

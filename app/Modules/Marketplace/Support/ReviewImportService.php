<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Pedido explícito 2026-08-16: um job de cron que bate na API de TODOS os
 * marketplaces conectados e traz pros produtos locais só nota, comentário,
 * imagens e nome do comprador — sem identificar de qual canal veio pro
 * público (ver Review::$hidden). Canal-agnóstico por design: usa o mesmo
 * `product_channel_listings` que já vincula produto local ↔ anúncio em
 * cada canal (ver ShopeeProductImportService/publishProduct), não sabe nem
 * precisa saber o formato de cada API.
 *
 * Um canal sem fetchReviews() implementado ainda (ver
 * AbstractMarketplaceDriver::fetchReviews()) lança RuntimeException na
 * primeira tentativa — tratado como "pula esse canal inteiro" em vez de
 * tentar de novo pra cada produto, já que a resposta vai ser sempre a
 * mesma pra qualquer external_id do mesmo canal.
 */
class ReviewImportService
{
    public function __construct(private readonly MarketplaceDriverManager $drivers) {}

    /**
     * @return array{products_checked: int, imported: int, updated: int, channels_skipped: array<int, string>}
     */
    public function importAll(): array
    {
        $summary = ['products_checked' => 0, 'imported' => 0, 'updated' => 0, 'channels_skipped' => []];

        $listingsByChannel = ProductChannelListing::query()
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->get(['product_id', 'channel', 'external_id'])
            ->groupBy('channel');

        foreach ($listingsByChannel as $channel => $listings) {
            try {
                $driver = $this->drivers->driver($channel);
            } catch (InvalidArgumentException) {
                $summary['channels_skipped'][] = $channel;

                continue;
            }

            foreach ($listings as $listing) {
                try {
                    $reviews = $driver->fetchReviews($listing->external_id);
                } catch (Throwable $exception) {
                    Log::channel('reviews')->info('reviews.channel_skipped', [
                        'channel' => $channel,
                        'message' => $exception->getMessage(),
                    ]);

                    $summary['channels_skipped'][] = $channel;

                    // Falha aqui é canal-wide (não configurado, ou
                    // fetchReviews() ainda não implementado) — tentar de
                    // novo pro próximo produto do MESMO canal só repetiria
                    // o mesmo erro.
                    continue 2;
                }

                $summary['products_checked']++;

                foreach ($reviews as $data) {
                    $imported = $this->upsert($channel, $listing->product_id, $data);
                    $summary[$imported ? 'imported' : 'updated']++;
                }
            }
        }

        $summary['channels_skipped'] = array_values(array_unique($summary['channels_skipped']));

        return $summary;
    }

    /**
     * @param  array{external_id: string, rating: int, comment: ?string, reviewer_name: string, images: array<int, string>, created_at: ?\Illuminate\Support\Carbon}  $data
     * @return bool true se foi criada agora (nunca vista antes)
     */
    private function upsert(string $channel, int $productId, array $data): bool
    {
        $review = Review::query()->firstOrNew(['channel' => $channel, 'external_id' => $data['external_id']]);
        $isNew = ! $review->exists;

        $review->fill([
            'product_id' => $productId,
            'reviewer_name' => $data['reviewer_name'],
            'rating' => $data['rating'],
            'comment' => $data['comment'],
        ]);

        // Data real da avaliação no canal, só na criação — não fica
        // "pulando" a cada execução do cron se o comentário mudar
        // (comprador editando) numa passada seguinte.
        if ($isNew && $data['created_at']) {
            $review->created_at = $data['created_at'];
        }

        $review->save();

        if ($isNew) {
            foreach (array_values($data['images']) as $position => $url) {
                $review->images()->create(['image_url' => $url, 'position' => $position]);
            }
        }

        return $isNew;
    }
}

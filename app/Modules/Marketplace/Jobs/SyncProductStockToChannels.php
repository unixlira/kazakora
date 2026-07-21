<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncProductStockToChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly Product $product)
    {
    }

    public function handle(MarketplaceDriverManager $manager): void
    {
        $listings = $this->product->channelListings()
            ->where('is_enabled', true)
            ->where('status', ProductChannelListing::STATUS_PUBLISHED)
            ->get();

        foreach ($listings as $listing) {
            try {
                $manager->driver($listing->channel)->updateStock($this->product, $listing);

                $listing->update(['last_synced_at' => now(), 'last_error' => null]);
            } catch (Throwable $exception) {
                $listing->update(['last_error' => $exception->getMessage()]);
            }
        }
    }
}

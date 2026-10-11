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

class PublishProductToChannel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly Product $product,
        public readonly ProductChannelListing $listing,
    ) {
    }

    public function handle(MarketplaceDriverManager $manager): void
    {
        $driver = $manager->driver($this->listing->channel);

        try {
            $externalId = $driver->publishProduct($this->product, $this->listing);

            $this->listing->update([
                'status' => ProductChannelListing::STATUS_PUBLISHED,
                'external_id' => $externalId,
                'last_synced_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $this->listing->update([
                'status' => ProductChannelListing::STATUS_ERROR,
                'last_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

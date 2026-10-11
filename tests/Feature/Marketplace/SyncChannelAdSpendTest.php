<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\MercadoLivreAdsService;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class SyncChannelAdSpendTest extends TestCase
{
    use RefreshDatabase;

    private function connectAccount(string $channel): void
    {
        MarketplaceAccount::create([
            'channel' => $channel,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123',
            'access_token' => 'fake',
            'connected_at' => now(),
        ]);
    }

    public function test_syncs_shopee_daily_rows_and_mercado_livre_daily_rows_into_the_same_table(): void
    {
        $this->connectAccount(MarketplaceAccount::CHANNEL_SHOPEE);
        $this->connectAccount(MarketplaceAccount::CHANNEL_MERCADO_LIVRE);

        $today = Carbon::today();

        $shopeeAds = Mockery::mock(ShopeeAdsService::class);
        $shopeeAds->shouldReceive('dailyPerformance')->once()->andReturn([
            ['date' => $today->toDateString(), 'impressions' => 1000, 'clicks' => 20, 'attributed_orders' => 2, 'attributed_gmv' => 150.0, 'spend' => 30.0],
        ]);
        $this->app->instance(ShopeeAdsService::class, $shopeeAds);

        $mlAds = Mockery::mock(MercadoLivreAdsService::class);
        $mlAds->shouldReceive('dailySpend')->andReturn([
            'date' => $today->toDateString(), 'impressions' => 500, 'clicks' => 10, 'attributed_orders' => 1, 'attributed_gmv' => 80.0, 'spend' => 12.0,
        ]);
        $this->app->instance(MercadoLivreAdsService::class, $mlAds);

        $this->artisan('ads:sync-spend', ['--dias' => 1])->assertSuccessful();

        $this->assertDatabaseHas('channel_ad_spends', [
            'channel' => 'shopee', 'date' => $today->toDateString(), 'spend' => 30.0, 'clicks' => 20,
        ]);
        $this->assertDatabaseHas('channel_ad_spends', [
            'channel' => 'mercado_livre', 'date' => $today->toDateString(), 'spend' => 12.0, 'clicks' => 10,
        ]);
    }

    public function test_running_twice_updates_in_place_instead_of_duplicating(): void
    {
        $this->connectAccount(MarketplaceAccount::CHANNEL_SHOPEE);

        $today = Carbon::today();

        $shopeeAds = Mockery::mock(ShopeeAdsService::class);
        $shopeeAds->shouldReceive('dailyPerformance')->twice()->andReturn([
            ['date' => $today->toDateString(), 'impressions' => 1000, 'clicks' => 20, 'attributed_orders' => 2, 'attributed_gmv' => 150.0, 'spend' => 30.0],
        ], [
            ['date' => $today->toDateString(), 'impressions' => 1200, 'clicks' => 25, 'attributed_orders' => 3, 'attributed_gmv' => 200.0, 'spend' => 45.0],
        ]);
        $this->app->instance(ShopeeAdsService::class, $shopeeAds);

        $mlAds = Mockery::mock(MercadoLivreAdsService::class);
        $mlAds->shouldReceive('dailySpend')->andReturn(null);
        $this->app->instance(MercadoLivreAdsService::class, $mlAds);

        $this->artisan('ads:sync-spend', ['--dias' => 1])->assertSuccessful();
        $this->artisan('ads:sync-spend', ['--dias' => 1])->assertSuccessful();

        $this->assertSame(1, ChannelAdSpend::query()->where('channel', 'shopee')->count());
        $this->assertDatabaseHas('channel_ad_spends', ['channel' => 'shopee', 'spend' => 45.0]);
    }

    public function test_skips_channels_that_are_not_connected_without_failing(): void
    {
        // Nenhuma conta conectada — comando precisa terminar OK mesmo assim.
        $this->artisan('ads:sync-spend')->assertSuccessful();

        $this->assertSame(0, ChannelAdSpend::query()->count());
    }
}

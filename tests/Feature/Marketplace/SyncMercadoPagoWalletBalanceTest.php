<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Models\ChannelWalletBalance;
use App\Services\MercadoPago\MercadoPagoWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncMercadoPagoWalletBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_the_balance_from_the_latest_ready_report_and_requests_a_new_one(): void
    {
        config(['services.mercadopago.access_token' => 'fake-token']);

        $wallet = Mockery::mock(MercadoPagoWalletService::class);
        $wallet->shouldReceive('latestReadyReport')->once()->andReturn([
            'file_name' => 'reserve-release-test.csv',
            'date_created' => '2026-08-10T08:00:00.000-03:00',
        ]);
        $wallet->shouldReceive('downloadBalance')->once()->with('reserve-release-test.csv')->andReturn(500.49);
        $wallet->shouldReceive('requestReport')->once();
        $this->app->instance(MercadoPagoWalletService::class, $wallet);

        $this->artisan('ads:sync-wallet-balance')->assertSuccessful();

        $this->assertDatabaseHas('channel_wallet_balances', [
            'channel' => 'mercado_livre',
            'balance' => 500.49,
        ]);
    }

    public function test_still_requests_a_new_report_even_when_none_is_ready_yet(): void
    {
        config(['services.mercadopago.access_token' => 'fake-token']);

        $wallet = Mockery::mock(MercadoPagoWalletService::class);
        $wallet->shouldReceive('latestReadyReport')->once()->andReturn(null);
        $wallet->shouldReceive('requestReport')->once();
        $this->app->instance(MercadoPagoWalletService::class, $wallet);

        $this->artisan('ads:sync-wallet-balance')->assertSuccessful();

        $this->assertSame(0, ChannelWalletBalance::count());
    }

    public function test_running_twice_updates_the_balance_in_place(): void
    {
        config(['services.mercadopago.access_token' => 'fake-token']);

        $wallet = Mockery::mock(MercadoPagoWalletService::class);
        $wallet->shouldReceive('latestReadyReport')->twice()->andReturn(
            ['file_name' => 'a.csv', 'date_created' => '2026-08-10T08:00:00.000-03:00'],
            ['file_name' => 'b.csv', 'date_created' => '2026-08-10T09:00:00.000-03:00'],
        );
        $wallet->shouldReceive('downloadBalance')->with('a.csv')->andReturn(500.49);
        $wallet->shouldReceive('downloadBalance')->with('b.csv')->andReturn(612.10);
        $wallet->shouldReceive('requestReport')->twice();
        $this->app->instance(MercadoPagoWalletService::class, $wallet);

        $this->artisan('ads:sync-wallet-balance')->assertSuccessful();
        $this->artisan('ads:sync-wallet-balance')->assertSuccessful();

        $this->assertSame(1, ChannelWalletBalance::query()->where('channel', 'mercado_livre')->count());
        $this->assertDatabaseHas('channel_wallet_balances', ['channel' => 'mercado_livre', 'balance' => 612.10]);
    }

    public function test_skips_entirely_when_no_access_token_is_configured(): void
    {
        config(['services.mercadopago.access_token' => null]);

        $this->artisan('ads:sync-wallet-balance')->assertSuccessful();

        $this->assertSame(0, ChannelWalletBalance::count());
    }
}

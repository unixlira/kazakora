<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Shopee\ShopeeAdsService;
use App\Services\Shopee\ShopeeWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Saldo da carteira (dashboard financeiro) e saldo de Ads (recargas)
 * vinham de chamada AO VIVO na Shopee em todo carregamento de página
 * (~1,2s cada, medido em produção 2026-09-29). Agora ficam em cache por
 * alguns minutos — mas falha nunca pode ser cacheada como sucesso, e
 * ?refresh=1 busca de novo na hora.
 */
class ShopeeLiveBalanceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_wallet_balance_is_cached_between_dashboard_loads(): void
    {
        $wallet = Mockery::mock(ShopeeWalletService::class);
        $wallet->shouldReceive('currentBalance')->once()->andReturn(321.45);
        $this->app->instance(ShopeeWalletService::class, $wallet);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/dashboard-financeiro')->assertOk();
        $this->actingAs($admin)->get('/admin/dashboard-financeiro')->assertOk();
    }

    public function test_wallet_failure_is_not_cached_and_refresh_forces_a_new_call(): void
    {
        $wallet = Mockery::mock(ShopeeWalletService::class);
        $wallet->shouldReceive('currentBalance')->once()->ordered()->andThrow(new RuntimeException('Shopee fora do ar'));
        $wallet->shouldReceive('currentBalance')->once()->ordered()->andReturn(100.25);
        $wallet->shouldReceive('currentBalance')->once()->ordered()->andReturn(200.75);
        $this->app->instance(ShopeeWalletService::class, $wallet);

        $admin = $this->admin();

        // Falha: página abre normal (fallback de sempre), nada em cache.
        $this->actingAs($admin)->get('/admin/dashboard-financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('walletBalances.shopee', null));
        // Tenta de novo e grava o sucesso.
        $this->actingAs($admin)->get('/admin/dashboard-financeiro')->assertOk();
        // Vem do cache — não chama a Shopee.
        $this->actingAs($admin)->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page->where('walletBalances.shopee', 100.25));
        // ?refresh=1 ignora o cache.
        $this->actingAs($admin)->get('/admin/dashboard-financeiro?refresh=1')
            ->assertInertia(fn ($page) => $page->where('walletBalances.shopee', 200.75));
    }

    public function test_ads_balance_is_cached_failure_is_not_and_refresh_refetches(): void
    {
        $ads = Mockery::mock(ShopeeAdsService::class);
        $ads->shouldReceive('currentBalance')->once()->ordered()->andThrow(new RuntimeException('timeout'));
        $ads->shouldReceive('currentBalance')->once()->ordered()->andReturn(50.5);
        $ads->shouldReceive('currentBalance')->once()->ordered()->andReturn(75.25);
        $this->app->instance(ShopeeAdsService::class, $ads);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/anuncios/recargas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('shopeeBalance', null));
        $this->actingAs($admin)->get('/admin/anuncios/recargas')
            ->assertInertia(fn ($page) => $page->where('shopeeBalance', 50.5));
        $this->actingAs($admin)->get('/admin/anuncios/recargas')
            ->assertInertia(fn ($page) => $page->where('shopeeBalance', 50.5));
        $this->actingAs($admin)->get('/admin/anuncios/recargas?refresh=1')
            ->assertInertia(fn ($page) => $page->where('shopeeBalance', 75.25));
    }
}

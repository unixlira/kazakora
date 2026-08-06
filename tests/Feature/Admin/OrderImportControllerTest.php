<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\WebhookTestFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class OrderImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function mockDriver(string $channel, array $importOrderResult): void
    {
        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('importOrder')->once()->andReturn($importOrderResult);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with($channel)->once()->andReturn($driver);
        $manager->shouldReceive('channels')->andReturn([
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            MarketplaceAccount::CHANNEL_SHOPEE,
            MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
        ]);

        $this->app->instance(MarketplaceDriverManager::class, $manager);
    }

    public function test_only_admin_can_access_the_form(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($manager)->get('/admin/importar-pedido')->assertForbidden();
        $this->actingAs($admin)->get('/admin/importar-pedido')->assertOk();
    }

    public function test_store_rejects_a_channel_without_a_registered_driver(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/importar-pedido', [
            'channel' => MarketplaceAccount::CHANNEL_AMAZON,
            'external_order_id' => '123',
        ])->assertSessionHasErrors('channel');
    }

    public function test_store_imports_a_new_order_via_the_real_driver_pipeline(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $normalized = WebhookTestFixtures::normalize(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            WebhookTestFixtures::raw(MarketplaceAccount::CHANNEL_MERCADO_LIVRE),
        );
        $this->mockDriver(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $normalized);

        $response = $this->actingAs($admin)->post('/admin/importar-pedido', [
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_order_id' => 'qualquer-coisa-aqui', // ignorado pelo driver mockado, só valida presença
        ]);

        $order = Order::query()->where('external_order_id', $normalized['external_order_id'])->firstOrFail();

        $response->assertRedirect(route('admin.pedidos.exibir', $order));
        $response->assertSessionHas('success');
        $this->assertSame(Order::STATUS_PAID, $order->status);
    }

    public function test_store_resyncs_status_instead_of_duplicating_when_order_already_exists(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $normalized = WebhookTestFixtures::normalize(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            WebhookTestFixtures::raw(MarketplaceAccount::CHANNEL_MERCADO_LIVRE),
        );

        // Pedido já existe localmente com status diferente do que o "canal"
        // (mockado) vai devolver — resincroniza em vez de duplicar.
        Order::create(array_merge([
            'origin' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'shipping_phone' => '11999999999',
        ], collect($normalized)->only([
            'external_order_id', 'shipping_zip', 'shipping_street', 'shipping_number',
            'shipping_neighborhood', 'shipping_city', 'shipping_state', 'subtotal', 'shipping_cost', 'total',
        ])->all(), ['shipping_name' => $normalized['buyer_name']]));

        $this->mockDriver(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $normalized);

        $this->actingAs($admin)->post('/admin/importar-pedido', [
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_order_id' => 'irrelevante',
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(Order::STATUS_PAID, Order::query()->firstOrFail()->status);
    }

    public function test_store_shows_the_real_error_when_the_driver_fails(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('importOrder')->once()->andThrow(new \RuntimeException('Pedido não encontrado no Mercado Livre.'));

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with(MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->once()->andReturn($driver);
        $manager->shouldReceive('channels')->andReturn([MarketplaceAccount::CHANNEL_MERCADO_LIVRE]);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $response = $this->actingAs($admin)->post('/admin/importar-pedido', [
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_order_id' => '999',
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Pedido não encontrado no Mercado Livre.', session('error'));
        $this->assertDatabaseCount('orders', 0);
    }
}

<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\ReviewImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * UF real do cliente no carrossel do checkout (2026-10-24): vem do pedido do
 * canal que gerou a avaliação (order_sn da Shopee); sem pedido nosso, sem UF.
 */
class ReviewImportUfTest extends TestCase
{
    use RefreshDatabase;

    private function importar(Product $produto, ?string $orderSn): void
    {
        ProductChannelListing::query()->firstOrCreate(
            ['product_id' => $produto->id, 'channel' => 'shopee'],
            ['external_id' => '999', 'is_enabled' => true],
        );

        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchReviews')->with('999')->andReturn([[
            'external_id' => 'r-1',
            'rating' => 5,
            'comment' => 'Chegou certinho e bem embalado',
            'reviewer_name' => 'm*****a',
            'order_sn' => $orderSn,
            'images' => [],
            'created_at' => null,
        ]]);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with('shopee')->andReturn($driver);

        (new ReviewImportService($manager))->importAll();
    }

    private function pedido(string $orderSn, string $uf): void
    {
        Order::create([
            'status' => Order::STATUS_COMPLETED, 'origin' => 'shopee', 'external_order_id' => $orderSn,
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '1', 'shipping_street' => 'R',
            'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'Curitiba', 'shipping_state' => $uf,
            'subtotal' => 100, 'total' => 100,
        ]);
    }

    public function test_grava_a_uf_do_pedido_da_shopee_que_gerou_a_avaliacao(): void
    {
        $this->pedido('261022AMVW096V', 'PR');

        $this->importar(Product::factory()->create(), '261022AMVW096V');

        $this->assertSame('PR', Review::query()->where('external_id', 'r-1')->value('uf'));
    }

    public function test_sem_pedido_nosso_fica_sem_uf_e_reimportacao_completa_depois(): void
    {
        $produto = Product::factory()->create();

        $this->importar($produto, '261022XXXX');
        $this->assertNull(Review::query()->where('external_id', 'r-1')->value('uf'));

        // O pedido chegou depois (importação de pedidos): o cron das avaliações completa.
        $this->pedido('261022XXXX', 'BA');
        $this->importar($produto, '261022XXXX');
        $this->assertSame('BA', Review::query()->where('external_id', 'r-1')->value('uf'));
    }
}

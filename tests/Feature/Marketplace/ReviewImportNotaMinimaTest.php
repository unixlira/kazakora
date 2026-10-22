<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\ReviewImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Avaliação de 1 ou 2 estrelas não é copiada do marketplace (pedido do
 * Lira 2026-10-21: as apagadas no admin voltavam a cada execução do cron).
 */
class ReviewImportNotaMinimaTest extends TestCase
{
    use RefreshDatabase;

    private function importar(Product $produto, int $nota): array
    {
        ProductChannelListing::query()->firstOrCreate(
            ['product_id' => $produto->id, 'channel' => 'shopee'],
            ['external_id' => '999', 'is_enabled' => true],
        );

        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchReviews')->with('999')->andReturn([[
            'external_id' => 'r-1',
            'rating' => $nota,
            'comment' => 'Comentário',
            'reviewer_name' => 'Joana',
            'images' => [],
            'created_at' => null,
        ]]);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with('shopee')->andReturn($driver);

        return (new ReviewImportService($manager))->importAll();
    }

    public function test_nao_copia_avaliacao_de_1_ou_2_estrelas(): void
    {
        $produto = Product::factory()->create();

        $resumo = $this->importar($produto, 2);

        $this->assertSame(1, $resumo['low_rating_skipped']);
        $this->assertSame(0, $resumo['imported']);
        $this->assertFalse(Review::query()->where('external_id', 'r-1')->exists());

        $resumo = $this->importar($produto, 3);

        $this->assertSame(1, $resumo['imported']);
    }
}

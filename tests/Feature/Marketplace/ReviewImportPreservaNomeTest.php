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
 * Reimportação de avaliações não sobrescreve o reviewer_name já gravado
 * (nome corrigido no admin voltava a cada execução do cron).
 */
class ReviewImportPreservaNomeTest extends TestCase
{
    use RefreshDatabase;

    private function importar(Product $produto, string $nomeNoCanal, int $nota = 4): array
    {
        ProductChannelListing::query()->firstOrCreate(
            ['product_id' => $produto->id, 'channel' => 'shopee'],
            ['external_id' => '999', 'is_enabled' => true],
        );

        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchReviews')->with('999')->andReturn([[
            'external_id' => 'r-1',
            'rating' => $nota,
            'comment' => 'Chegou certinho',
            'reviewer_name' => $nomeNoCanal,
            'images' => [],
            'created_at' => null,
        ]]);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with('shopee')->andReturn($driver);

        return (new ReviewImportService($manager))->importAll();
    }

    public function test_reimportacao_preserva_nome_ja_gravado(): void
    {
        $produto = Product::factory()->create();

        $this->importar($produto, 'm*****a');
        Review::query()->where('external_id', 'r-1')->update(['reviewer_name' => 'Maria']);

        $resumo = $this->importar($produto, 'm*****a');

        $this->assertSame(1, $resumo['updated']);
        $this->assertSame('Maria', Review::query()->where('external_id', 'r-1')->value('reviewer_name'));
    }

    public function test_preenche_nome_quando_avaliacao_esta_sem_nome(): void
    {
        $produto = Product::factory()->create();

        $this->importar($produto, 'Joana');
        Review::query()->where('external_id', 'r-1')->update(['reviewer_name' => '']);

        $this->importar($produto, 'Joana');

        $this->assertSame('Joana', Review::query()->where('external_id', 'r-1')->value('reviewer_name'));
    }

    public function test_nao_copia_avaliacao_de_1_ou_2_estrelas(): void
    {
        $produto = Product::factory()->create();

        $resumo = $this->importar($produto, 'Joana', 2);

        $this->assertSame(1, $resumo['low_rating_skipped']);
        $this->assertSame(0, $resumo['imported']);
        $this->assertFalse(Review::query()->where('external_id', 'r-1')->exists());

        $resumo = $this->importar($produto, 'Joana', 3);

        $this->assertSame(1, $resumo['imported']);
    }
}

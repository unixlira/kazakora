<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductMediaBackfillService;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29: bike ergométrica (produto 82) com 9 linhas em
 * product_images e nenhum arquivo no disco — o backfill achava que o
 * produto "tinha foto" e o card do KoraSync ficava sem imagem.
 */
class ProductMediaBackfillBrokenFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $gd = imagecreatetruecolor(10, 10);
        ob_start();
        imagejpeg($gd);
        $jpeg = ob_get_clean();

        Http::fake(['*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);

        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchItemImages')->andReturn(['https://canal.test/a.jpg', 'https://canal.test/b.jpg']);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);

        $this->app->instance(MarketplaceDriverManager::class, $manager);
    }

    private function produtoAnunciado(): Product
    {
        $product = Product::factory()->create();

        ProductChannelListing::query()->create([
            'product_id' => $product->id,
            'channel' => 'mercado_livre',
            'is_enabled' => true,
            'status' => 'published',
            'external_id' => 'MLB123',
        ]);

        return $product;
    }

    public function test_replaces_images_whose_files_are_all_missing(): void
    {
        $product = $this->produtoAnunciado();
        $product->images()->create(['path' => 'products/x/sumiu-1.jpg', 'position' => 0, 'is_primary' => true, 'thumb_path' => 'products/x/thumbs/sumiu-1.jpg']);
        $product->images()->create(['path' => 'products/x/sumiu-2.jpg', 'position' => 1, 'is_primary' => false, 'thumb_path' => 'products/x/thumbs/sumiu-2.jpg']);

        $salvas = app(ProductMediaBackfillService::class)->fill($product);

        $this->assertSame(2, $salvas);

        $imagens = $product->images()->get();
        $this->assertCount(2, $imagens);
        $this->assertNotContains('products/x/sumiu-1.jpg', $imagens->pluck('path'));

        foreach ($imagens as $imagem) {
            Storage::disk('public')->assertExists($imagem->path);
        }
    }

    public function test_keeps_images_when_at_least_one_file_still_exists(): void
    {
        $product = $this->produtoAnunciado();
        Storage::disk('public')->put('products/x/existe.jpg', 'bytes');
        $product->images()->create(['path' => 'products/x/existe.jpg', 'position' => 0, 'is_primary' => true, 'thumb_path' => 'products/x/thumbs/existe.jpg']);
        $product->images()->create(['path' => 'products/x/sumiu.jpg', 'position' => 1, 'is_primary' => false, 'thumb_path' => 'products/x/thumbs/sumiu.jpg']);

        $this->assertSame(0, app(ProductMediaBackfillService::class)->fill($product));
        $this->assertCount(2, $product->images()->get());
    }

    public function test_keeps_broken_rows_when_the_channel_has_no_photo(): void
    {
        $product = $this->produtoAnunciado();
        $product->images()->create(['path' => 'products/x/sumiu.jpg', 'position' => 0, 'is_primary' => true, 'thumb_path' => 'products/x/thumbs/sumiu.jpg']);

        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchItemImages')->andReturn([]);
        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $this->assertSame(0, app(ProductMediaBackfillService::class)->fill($product));
        $this->assertSame(['products/x/sumiu.jpg'], $product->images()->pluck('path')->all());
    }
}

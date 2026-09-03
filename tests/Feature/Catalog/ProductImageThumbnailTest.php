<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductImageThumbnailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A miniatura da vitrine é sempre um arquivo NOVO ao lado da original —
 * nunca uma edição da original, que é o que a página de produto usa no zoom
 * e o que já foi publicado no Mercado Livre e na Shopee.
 */
class ProductImageThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD não está disponível neste ambiente.');
        }

        Storage::fake('public');
    }

    private function putJpeg(string $path, int $width = 1600, int $height = 1200): void
    {
        Storage::disk('public')->put($path, '');

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));
        imagejpeg($image, Storage::disk('public')->path($path), 82);
        imagedestroy($image);
    }

    public function test_a_new_product_image_is_born_with_a_thumbnail(): void
    {
        $product = Product::factory()->create();
        $this->putJpeg("products/{$product->id}/foto.jpg");

        $image = $product->images()->create([
            'path' => "products/{$product->id}/foto.jpg",
            'position' => 0,
            'is_primary' => true,
        ]);

        $this->assertNotNull($image->thumb_path);
        Storage::disk('public')->assertExists($image->thumb_path);

        // A original continua exatamente onde estava, no tamanho que estava.
        Storage::disk('public')->assertExists("products/{$product->id}/foto.jpg");
        [$width] = getimagesize(Storage::disk('public')->path("products/{$product->id}/foto.jpg"));
        $this->assertSame(1600, $width);

        [$thumbWidth] = getimagesize(Storage::disk('public')->path($image->thumb_path));
        $this->assertSame(512, $thumbWidth);

        $this->assertLessThan(
            Storage::disk('public')->size("products/{$product->id}/foto.jpg"),
            Storage::disk('public')->size($image->thumb_path)
        );
    }

    public function test_thumb_url_falls_back_to_the_original_when_there_is_no_thumbnail(): void
    {
        $product = Product::factory()->create();

        // Sem arquivo no disco a geração não tem o que ler: a foto entra
        // assim mesmo, só sem miniatura — e a vitrine segue mostrando a
        // original em vez de um quadro vazio.
        $image = $product->images()->create([
            'path' => "products/{$product->id}/inexistente.jpg",
            'position' => 0,
            'is_primary' => true,
        ]);

        $this->assertNull($image->thumb_path);
        $this->assertSame($image->url, $image->thumb_url);
    }

    public function test_deleting_the_image_deletes_its_thumbnail(): void
    {
        $product = Product::factory()->create();
        $this->putJpeg("products/{$product->id}/foto.jpg");

        $image = $product->images()->create([
            'path' => "products/{$product->id}/foto.jpg",
            'position' => 0,
            'is_primary' => true,
        ]);

        $thumbPath = $image->thumb_path;
        $image->delete();

        Storage::disk('public')->assertMissing($thumbPath);
    }

    public function test_generate_returns_null_for_a_file_that_is_not_an_image(): void
    {
        Storage::disk('public')->put('products/1/nao-e-imagem.jpg', 'isto aqui nao e um jpeg');

        $this->assertNull(ProductImageThumbnailer::generate('products/1/nao-e-imagem.jpg'));
    }
}

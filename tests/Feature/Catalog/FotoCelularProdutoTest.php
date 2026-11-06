<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tarefa 3886 (Lira, 2026-11-06): foto de 800px para o celular e miniatura
 * de 160px na galeria, sempre arquivo NOVO ao lado da original, que fica
 * intacta (zoom e anúncios do ML e da Shopee).
 */
class FotoCelularProdutoTest extends TestCase
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

    private function putJpeg(string $path, int $width = 2048, int $height = 2048): void
    {
        Storage::disk('public')->put($path, '');

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));
        imagejpeg($image, Storage::disk('public')->path($path), 82);
        imagedestroy($image);
    }

    public function test_foto_nova_nasce_com_versao_do_celular_e_miniatura_da_galeria(): void
    {
        $product = Product::factory()->create();
        $this->putJpeg("products/{$product->id}/foto.jpg");
        $antes = Storage::disk('public')->get("products/{$product->id}/foto.jpg");

        $image = $product->images()->create(['path' => "products/{$product->id}/foto.jpg", 'position' => 0, 'is_primary' => true]);

        [$celular] = getimagesize(Storage::disk('public')->path($image->celular_path));
        [$micro] = getimagesize(Storage::disk('public')->path($image->micro_path));
        $this->assertSame(800, $celular);
        $this->assertSame(160, $micro);
        $this->assertStringContainsString('/celular/', $image->celular_url);
        $this->assertStringContainsString('/micros/', $image->micro_url);
        $this->assertSame($antes, Storage::disk('public')->get("products/{$product->id}/foto.jpg"));

        $image->delete();
        Storage::disk('public')->assertMissing($image->celular_path);
        Storage::disk('public')->assertMissing($image->micro_path);
        Storage::disk('public')->assertExists("products/{$product->id}/foto.jpg");
    }

    public function test_sem_as_versoes_novas_cai_nas_de_antes(): void
    {
        $image = new ProductImage(['path' => 'products/p/a.jpg', 'medium_path' => 'products/p/medias/a.webp', 'mini_path' => 'products/p/minis/a.webp']);

        $this->assertSame($image->media_url, $image->celular_url);
        $this->assertSame($image->mini_url, $image->micro_url);
    }

    public function test_comando_gera_so_para_os_produtos_pedidos_e_sem_mexer_na_original(): void
    {
        $escolhido = Product::factory()->create();
        $outro = Product::factory()->create();
        $fotos = [];
        foreach ([$escolhido, $outro] as $product) {
            $this->putJpeg("products/{$product->id}/foto.jpg");
            $fotos[$product->id] = ProductImage::withoutEvents(fn () => ProductImage::create([
                'product_id' => $product->id, 'path' => "products/{$product->id}/foto.jpg", 'position' => 0, 'is_primary' => true,
            ]));
        }
        $original = Storage::disk('public')->get("products/{$escolhido->id}/foto.jpg");

        $this->artisan('catalog:generate-celular-images', ['--produto' => [$escolhido->id]])->assertSuccessful();

        $this->assertNotNull($fotos[$escolhido->id]->fresh()->celular_path);
        $this->assertNotNull($fotos[$escolhido->id]->fresh()->micro_path);
        $this->assertNull($fotos[$outro->id]->fresh()->celular_path);
        $this->assertSame($original, Storage::disk('public')->get("products/{$escolhido->id}/foto.jpg"));

        // Rodar de novo não gera nada.
        $this->artisan('catalog:generate-celular-images', ['--produto' => [$escolhido->id]])
            ->expectsOutput('Nenhuma foto sem a versão do celular.')
            ->assertSuccessful();
    }

    public function test_pagina_de_produto_pre_carrega_a_foto_do_celular_no_srcset(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $foto = ProductImage::withoutEvents(fn () => ProductImage::create([
            'product_id' => $produto->id,
            'path' => 'products/p/original.jpg',
            'thumb_path' => 'products/p/thumbs/original.webp',
            'medium_path' => 'products/p/medias/original.webp',
            'mini_path' => 'products/p/minis/original.webp',
            'celular_path' => 'products/p/celular/original.webp',
            'micro_path' => 'products/p/micros/original.webp',
            'position' => 0,
            'is_primary' => true,
        ]));

        $html = $this->get("/produtos/{$produto->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('imagesrcset="'.$foto->thumb_url.' 512w, '.$foto->celular_url.' 800w, '.$foto->media_url.' 1200w"', $html);
        $this->assertStringContainsString('imagesizes="(max-width: 767px) and (min-resolution: 2.5dppx) calc((100vw - 40px) * 0.66), (max-width: 767px) calc(100vw - 40px)', $html);
        // As URLs novas vão nas fotos do produto da página (props do Inertia).
        $this->assertStringContainsString(str_replace('/', '\/', $foto->micro_url), $html);
    }
}

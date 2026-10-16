<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foto da variação (2026-10-15): a capa do pai é a montagem com todos os
 * modelos; a foto do modelo do pai é a marcada com is_variant, e é ela que
 * fica destacada na galeria quando a variação do pai é escolhida.
 */
class FotoDaVariacaoTest extends TestCase
{
    use RefreshDatabase;

    private function foto(Product $product, string $path, int $position, bool $primary = false, bool $variant = false): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            'path' => $path,
            'thumb_path' => $path,
            'position' => $position,
            'is_primary' => $primary,
            'is_variant' => $variant,
        ]);
    }

    public function test_pai_destaca_a_foto_do_modelo_e_nao_a_capa(): void
    {
        $pai = Product::factory()->create(['is_active' => true]);
        $filho = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id]);
        $capa = $this->foto($pai, 'products/pai/capa.jpg', 0, primary: true);
        $this->foto($pai, 'products/pai/detalhe.jpg', 1);
        $modelo = $this->foto($pai, 'products/pai/modelo.jpg', 2, variant: true);
        $fotoFilho = $this->foto($filho, 'products/filho/modelo.jpg', 0, primary: true);

        $this->get("/produtos/{$pai->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('galeria.0.url', $capa->url)
                ->where('galeria.0.variant_id', null)
                ->where('galeria.2.url', $modelo->url)
                ->where('galeria.2.variant_id', $pai->id)
                ->where('galeria.3.url', $fotoFilho->url)
                ->where('galeria.3.variant_id', $filho->id)
                ->has('galeria', 4));

        // Na página do filho, o seletor recebe a foto do modelo do pai.
        $this->get("/produtos/{$filho->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('variations.0.images.0.url', $modelo->url)
                ->where('galeria.2.variant_id', $pai->id));
    }

    public function test_sem_foto_marcada_segue_a_principal(): void
    {
        $pai = Product::factory()->create(['is_active' => true]);
        Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id]);
        $capa = $this->foto($pai, 'products/pai/capa.jpg', 0, primary: true);

        $this->get("/produtos/{$pai->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('galeria.0.url', $capa->url)
                ->where('galeria.0.variant_id', $pai->id));
    }
}

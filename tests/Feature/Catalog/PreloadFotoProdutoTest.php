<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PageSpeed 2026-10-21 (LCP da página de produto): a foto grande sai pedida
 * no HTML, com o mesmo srcset/sizes da galeria, e a loja usa os selos e o
 * logo em WebP (e-mail continua no PNG).
 */
class PreloadFotoProdutoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_de_produto_pre_carrega_a_primeira_foto_com_srcset(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $foto = ProductImage::create([
            'product_id' => $produto->id,
            'path' => 'products/p/original.jpg',
            'thumb_path' => 'products/p/thumbs/original.webp',
            'medium_path' => 'products/p/medias/original.webp',
            'mini_path' => 'products/p/minis/original.webp',
            'position' => 0,
            'is_primary' => true,
        ]);

        $html = $this->get("/produtos/{$produto->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="preload" as="image" fetchpriority="high"', $html);
        $this->assertStringContainsString('href="'.$foto->media_url.'"', $html);
        $this->assertStringContainsString('imagesrcset="'.$foto->thumb_url.' 512w, '.$foto->media_url.' 1200w"', $html);
        $this->assertStringContainsString('imagesizes="(max-width: 767px) calc(100vw - 40px), (max-width: 900px) 75vw, (max-width: 1320px) 48vw, 600px"', $html);
        $this->assertStringContainsString('/images/marca/logo-nav.webp', $html);
    }

    public function test_home_nao_pre_carrega_foto_de_produto(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('imagesizes="(max-width: 767px) calc(100vw - 40px)', $html);
    }
}

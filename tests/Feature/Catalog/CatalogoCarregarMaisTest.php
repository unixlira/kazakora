<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Catálogo 8 por vez + "Carregar mais" (pedido 2026-10-10) e card leve: no
 * máximo 2 fotos por produto na vitrine.
 */
class CatalogoCarregarMaisTest extends TestCase
{
    use RefreshDatabase;

    public function test_vitrine_mostra_8_e_a_proxima_pagina_vem_so_com_os_produtos(): void
    {
        Product::factory()->count(10)->create(['is_active' => true]);

        $this->get('/?todos=1')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Home', false)
            ->has('products.data', 8)
            ->where('products.last_page', 2));

        $versao = $this->get('/?todos=1')->viewData('page')['version'] ?? '';
        $this->get('/?todos=1&page=2', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $versao,
            'X-Inertia-Partial-Component' => 'Catalog/Home',
            'X-Inertia-Partial-Data' => 'products',
        ])->assertOk()
            ->assertJsonCount(2, 'props.products.data')
            ->assertJsonMissingPath('props.banners');
    }

    public function test_card_leva_no_maximo_duas_fotos_com_a_principal_primeiro(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        foreach ([0, 1, 2, 3] as $position) {
            $product->images()->create(['path' => "products/x{$position}.jpg", 'position' => $position, 'is_primary' => $position === 2]);
        }

        $this->get('/?todos=1')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products.data.0.images', 2)
            ->where('products.data.0.images.0.is_primary', true));
    }

    public function test_departamento_usa_foto_de_produto_e_filtra_a_vitrine(): void
    {
        $cozinha = Category::factory()->create(['name' => 'Cozinha', 'slug' => 'cozinha', 'image_path' => null]);
        $outra = Category::factory()->create(['slug' => 'outra']);
        $panela = Product::factory()->create(['is_active' => true, 'category_id' => $cozinha->id, 'name' => 'Panela']);
        $panela->images()->create(['path' => 'products/panela.jpg', 'position' => 0, 'is_primary' => true]);
        Product::factory()->create(['is_active' => true, 'category_id' => $outra->id]);

        $this->get('/?categoria=cozinha')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Panela')
            ->where('filters.categoria', 'cozinha')
            ->where('categories', fn ($departamentos) => collect($departamentos)->firstWhere('slug', 'cozinha')['image_url'] !== null));
    }
}

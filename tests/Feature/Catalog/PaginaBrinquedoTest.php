<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Página de brinquedo (pedido do Lira 24/11/2026): produto da categoria de
 * brinquedos abre com layout "brinquedo"; o resto continua no "padrao". O
 * produto (ou o pai) pode forçar um dos dois em products.layout_pagina.
 */
class PaginaBrinquedoTest extends TestCase
{
    use RefreshDatabase;

    private function categoria(string $slug): Category
    {
        return Category::factory()->create(['slug' => $slug, 'name' => ucfirst(str_replace('-', ' ', $slug))]);
    }

    public function test_brinquedo_abre_com_layout_brinquedo(): void
    {
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id]);

        $this->get("/produtos/{$produto->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false)
                ->where('product.id', $produto->id)
                ->where('layout', 'brinquedo'));
    }

    public function test_produto_comum_continua_no_layout_padrao(): void
    {
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('ferramentas-e-automotivo')->id]);

        $this->get("/produtos/{$produto->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false)
                ->where('product.id', $produto->id)
                ->where('layout', 'padrao'));
    }

    public function test_produto_pode_forcar_o_layout(): void
    {
        $ferramenta = Product::factory()->create([
            'is_active' => true, 'category_id' => $this->categoria('ferramentas-e-automotivo')->id, 'layout_pagina' => 'brinquedo',
        ]);
        $brinquedoPadrao = Product::factory()->create([
            'is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id, 'layout_pagina' => 'padrao',
        ]);

        $this->get("/produtos/{$ferramenta->slug}")->assertInertia(fn ($page) => $page->where('layout', 'brinquedo'));
        $this->get("/produtos/{$brinquedoPadrao->slug}")->assertInertia(fn ($page) => $page->where('layout', 'padrao'));
    }

    public function test_variacao_segue_o_layout_do_pai_e_leva_os_kits(): void
    {
        $categoria = $this->categoria('gadgets');
        $pai = Product::factory()->create(['is_active' => true, 'category_id' => $categoria->id, 'layout_pagina' => 'brinquedo', 'variation' => 'Livro Falante', 'price' => 152]);
        $kit = Product::factory()->create([
            'is_active' => true, 'category_id' => $categoria->id, 'parent_product_id' => $pai->id,
            'variation' => 'Livro Falante + Lousa Mágica LED', 'price' => 184.90,
        ]);

        $this->get("/produtos/{$kit->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('layout', 'brinquedo')
                ->where('variations.0.id', $pai->id)
                ->where('variations.0.variation', 'Livro Falante'));

        $this->get("/produtos/{$pai->slug}")
            ->assertInertia(fn ($page) => $page->where('layout', 'brinquedo')
                ->where('variations.0.variation', 'Livro Falante + Lousa Mágica LED')
                ->missing('product.cost_price'));
    }

    public function test_versao_1_nao_muda(): void
    {
        $produto = Product::factory()->create(['is_active' => true, 'category_id' => $this->categoria('brinquedos-e-games')->id]);

        $this->get("/produtos/{$produto->slug}?v=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetail', false));
    }

    public function test_som_da_pagina_de_brinquedo_esta_publicado_e_leve(): void
    {
        $arquivo = public_path('sons/aeee.mp3');

        $this->assertFileExists($arquivo);
        $this->assertLessThan(60 * 1024, filesize($arquivo));
    }
}

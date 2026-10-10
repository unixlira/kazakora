<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Busca do site (pedido 2026-10-10): palavras em qualquer ordem, marca/departamento e ordenação. */
class BuscaDoSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_busca_por_palavras_em_qualquer_ordem_marca_e_departamento(): void
    {
        $cozinha = Category::factory()->create(['name' => 'Cozinha']);
        $power = Product::factory()->create(['name' => 'Power Bank 26000mAh Carregador Portátil', 'brand' => 'Kora', 'is_active' => true, 'price' => 100]);
        $panela = Product::factory()->create(['name' => 'Panela de Pressão', 'category_id' => $cozinha->id, 'is_active' => true, 'price' => 50]);
        Product::factory()->create(['name' => 'Trena de Aço', 'is_active' => true]);

        $ids = fn (string $url) => collect($this->get($url)->viewData('page')['props']['products']['data'])->pluck('id')->all();

        $this->assertSame([$power->id], $ids('/?search=carregador+power'));
        $this->assertSame([$power->id], $ids('/?search=kora'));
        $this->assertSame([$panela->id], $ids('/?search=cozinha'));
        $this->assertSame([], $ids('/?search=geladeira'));
    }

    public function test_ordena_por_preco_que_o_cliente_paga(): void
    {
        $caro = Product::factory()->create(['name' => 'Item caro', 'price' => 200, 'discount_percentage' => null, 'is_active' => true]);
        $promo = Product::factory()->create(['name' => 'Item promo', 'price' => 300, 'discount_percentage' => 80, 'is_active' => true]); // paga 60
        $medio = Product::factory()->create(['name' => 'Item medio', 'price' => 100, 'is_active' => true]);

        $this->get('/?todos=1&ordenar=menor_preco')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.id', $promo->id)
            ->where('products.data.1.id', $medio->id)
            ->where('products.data.2.id', $caro->id)
            ->where('filters.ordenar', 'menor_preco'));
    }
}

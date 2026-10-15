<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Busca instantânea do topo (pedido 2026-10-15, como na Beauty Kora): foto e nome ao digitar. */
class BuscaInstantaneaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sugere_foto_nome_e_preco_com_a_regra_da_busca(): void
    {
        $cozinha = Category::factory()->create(['name' => 'Cozinha']);
        $power = Product::factory()->create(['name' => 'Power Bank 26000mAh Carregador Portátil', 'is_active' => true, 'price' => 100]);
        $panela = Product::factory()->create(['name' => 'Panela de Pressão', 'category_id' => $cozinha->id, 'is_active' => true]);
        Product::factory()->create(['name' => 'Carregador inativo', 'is_active' => false]);
        Product::factory()->create(['name' => 'Carregador variação', 'is_active' => true, 'parent_product_id' => $power->id]);

        $this->getJson('/busca/sugestoes?q=carregador+power')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('produtos.0.slug', $power->slug)
            ->assertJsonPath('produtos.0.nome', $power->name)
            ->assertJsonStructure(['produtos' => [['nome', 'slug', 'imagem', 'preco']], 'total'])
            ->assertJsonMissingPath('produtos.0.cost_price');

        $this->getJson('/busca/sugestoes?q=cozinha')->assertJsonPath('produtos.0.slug', $panela->slug);
        $this->getJson('/busca/sugestoes?q=geladeira')->assertExactJson(['produtos' => [], 'total' => 0]);
    }

    public function test_quem_comeca_com_o_termo_vem_primeiro_e_no_maximo_8(): void
    {
        Product::factory()->count(9)->create(['name' => 'Kit faca de cozinha', 'is_active' => true]);
        $faca = Product::factory()->create(['name' => 'Faca do chef', 'is_active' => true]);

        $resposta = $this->getJson('/busca/sugestoes?q=faca')->assertJsonPath('total', 10);

        $this->assertCount(8, $resposta->json('produtos'));
        $this->assertSame($faca->slug, $resposta->json('produtos.0.slug'));
    }

    public function test_termo_curto_nao_busca(): void
    {
        Product::factory()->create(['name' => 'X', 'is_active' => true]);

        $this->getJson('/busca/sugestoes?q=x')->assertExactJson(['produtos' => [], 'total' => 0]);
    }

    public function test_resultado_da_busca_sem_departamentos_a_mostra(): void
    {
        $cozinha = Category::factory()->create(['name' => 'Cozinha']);
        Product::factory()->create(['name' => 'Panela de Pressão', 'category_id' => $cozinha->id, 'is_active' => true]);

        $this->get('/?search=panela')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.total', 1)
            ->where('departamentosFiltro', []));

        // No departamento a coluna continua.
        $this->get('/?categoria='.$cozinha->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('departamentosFiltro.0.slug', $cozinha->slug));
    }
}

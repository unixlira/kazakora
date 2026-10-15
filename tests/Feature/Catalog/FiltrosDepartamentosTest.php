<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Filtros de /ofertas em todo departamento, categoria e busca (pedido
 * 2026-10-15): coluna de departamentos com a quantidade, desconto e faixa de
 * preço pelo que o card mostra.
 */
class FiltrosDepartamentosTest extends TestCase
{
    use RefreshDatabase;

    private Product $panela;

    private Product $pote;

    private Product $caixa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => false]);
        Cache::flush();

        $casa = Category::factory()->create(['name' => 'Casa', 'slug' => 'casa']);
        $som = Category::factory()->create(['name' => 'Som', 'slug' => 'som']);
        $produto = fn (string $nome, float $preco, Category $categoria, array $extra = []) => Product::factory()->create([
            'name' => $nome, 'price' => $preco, 'stock' => 5, 'is_active' => true, 'category_id' => $categoria->id,
            'discount_percentage' => null, 'discount_amount' => null, ...$extra,
        ]);

        $this->panela = $produto('Panela', 150, $casa, ['discount_percentage' => 25]);
        $this->pote = $produto('Pote', 40, $casa);
        $this->caixa = $produto('Caixa de som', 300, $som);

        OfertaDoDia::query()->create(['data' => now()->toDateString(), 'product_id' => $this->pote->id, 'posicao' => 1,
            'desconto_extra' => 10, 'preco_antes' => 40, 'preco_oferta' => 36, 'lucro_estimado' => 5]);
    }

    private function ids(string $url): array
    {
        return collect($this->get($url)->assertOk()->viewData('page')['props']['products']['data'])->pluck('id')->sort()->values()->all();
    }

    public function test_departamento_traz_a_coluna_com_a_quantidade_de_cada_um(): void
    {
        $this->get('/?categoria=casa')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Home', false)
            ->has('products.data', 2)
            ->where('departamentosFiltro', [
                ['slug' => 'casa', 'name' => 'Casa', 'total' => 2],
                ['slug' => 'som', 'name' => 'Som', 'total' => 1],
            ]));
    }

    public function test_desconto_e_preco_seguem_o_card(): void
    {
        // Panela 25% cadastrado; Pote 10% da Oferta do Dia; Caixa sem desconto.
        $this->assertSame([$this->panela->id], $this->ids('/?desconto=20'));
        $this->assertSame(collect([$this->panela->id, $this->pote->id])->sort()->values()->all(), $this->ids('/?desconto=10'));

        // Pote sai a R$ 36 na Oferta do Dia; Panela a R$ 112,50.
        $this->assertSame([$this->pote->id], $this->ids('/?preco=ate-50'));
        $this->assertSame([$this->panela->id], $this->ids('/?preco=100-200&categoria=casa'));
        $this->assertSame([], $this->ids('/?preco=100-200&categoria=som'));

        // A contagem por departamento respeita o preço escolhido.
        $this->get('/?preco=200-mais')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('departamentosFiltro', [['slug' => 'som', 'name' => 'Som', 'total' => 1]])
            ->where('filters.preco', '200-mais'));
    }

    public function test_ordem_por_preco_segue_o_card(): void
    {
        // Pote custa 40 no cadastro, mas sai a 36 na Oferta do Dia: vem antes do Copo de 38.
        $copo = Product::factory()->create(['name' => 'Copo', 'price' => 38, 'stock' => 5, 'is_active' => true,
            'category_id' => $this->pote->category_id, 'discount_percentage' => null, 'discount_amount' => null]);

        $ordem = fn (string $url) => collect($this->get($url)->viewData('page')['props']['products']['data'])->pluck('id')->all();

        $this->assertSame([$this->pote->id, $copo->id, $this->panela->id], $ordem('/?categoria=casa&ordenar=menor_preco'));
        $this->assertSame([$this->caixa->id, $this->panela->id, $copo->id, $this->pote->id], $ordem('/?todos=1&ordenar=maior_preco'));
    }

    public function test_busca_continua_com_filtro(): void
    {
        $this->assertSame([$this->pote->id], $this->ids('/?search=pote&preco=ate-50'));
        $this->assertSame([], $this->ids('/?search=caixa&desconto=10'));
    }
}

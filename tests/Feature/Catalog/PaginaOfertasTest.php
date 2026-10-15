<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\OfertaRelampago;
use App\Modules\Catalog\Models\Product;
use App\Support\SiteAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Página /ofertas (pedido 2026-10-15): Oferta Relâmpago da KazaKora no ar e
 * Ofertas do Dia de hoje, com filtro por departamento, desconto, preço e
 * "termina em breve"; padrão por maior desconto.
 */
class PaginaOfertasTest extends TestCase
{
    use RefreshDatabase;

    private Product $relampago;

    private Product $doDia;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => false]);
        $this->travelTo(now()->setTime(12, 0));

        $casa = Category::factory()->create(['name' => 'Casa', 'slug' => 'casa']);
        $som = Category::factory()->create(['name' => 'Som', 'slug' => 'som']);
        $produto = fn (string $nome, float $preco, Category $categoria) => Product::factory()->create([
            'name' => $nome, 'price' => $preco, 'stock' => 5, 'is_active' => true, 'category_id' => $categoria->id,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);

        $this->relampago = $produto('Caixa de som', 200, $som);
        $this->doDia = $produto('Pote', 40, $casa);
        $produto('Sem oferta', 60, $casa);

        OfertaRelampago::create(['site' => SiteAtual::KAZAKORA, 'product_id' => $this->relampago->id, 'desconto_pct' => 30,
            'inicio_em' => now()->subHour(), 'fim_em' => now()->addHour(), 'ativo' => true]);
        // Oferta Relâmpago de outro site não entra.
        OfertaRelampago::create(['site' => SiteAtual::FERRAKORA, 'product_id' => $this->doDia->id, 'desconto_pct' => 50,
            'inicio_em' => now()->subHour(), 'fim_em' => now()->addHour(), 'ativo' => true]);
        OfertaDoDia::query()->create(['data' => now()->toDateString(), 'product_id' => $this->doDia->id, 'posicao' => 1,
            'desconto_extra' => 10, 'preco_antes' => 40, 'preco_oferta' => 36, 'lucro_estimado' => 5]);
    }

    public function test_lista_relampago_e_oferta_do_dia_por_maior_desconto(): void
    {
        $this->get('/ofertas')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totalOfertas', 2)
            ->has('produtos', 2)
            ->where('produtos.0.id', $this->relampago->id)
            ->where('produtos.0.desconto_oferta', 30)
            ->where('produtos.1.id', $this->doDia->id)
            ->where('produtos.1.desconto_oferta', 10)
            ->has('departamentos', 2));
    }

    public function test_filtros_e_ordem(): void
    {
        $this->get('/ofertas?categoria=casa')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('produtos', 1)->where('produtos.0.id', $this->doDia->id));

        $this->get('/ofertas?desconto=20')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('produtos', 1)->where('produtos.0.id', $this->relampago->id)->has('departamentos', 1));

        $this->get('/ofertas?preco=ate-50')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('produtos', 1)->where('produtos.0.id', $this->doDia->id));

        // Relâmpago acaba em 1 h; a do dia só à meia-noite.
        $this->get('/ofertas?acabando=1')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('produtos', 1)->where('produtos.0.id', $this->relampago->id));

        $this->get('/ofertas?ordenar=menor_preco')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('produtos.0.id', $this->doDia->id));
    }
}

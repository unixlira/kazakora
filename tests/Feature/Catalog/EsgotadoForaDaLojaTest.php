<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Produto esgotado não aparece na loja (pedido 2026-10-13); duplicados só no relatório. */
class EsgotadoForaDaLojaTest extends TestCase
{
    use RefreshDatabase;

    public function test_esgotado_some_da_busca_mas_pai_com_variacao_em_estoque_fica(): void
    {
        $comEstoque = Product::factory()->create(['name' => 'Bicicleta Spinning Mecanica', 'stock' => 5, 'is_active' => true]);
        Product::factory()->create(['name' => 'Bicicleta Spinning Magnetica', 'stock' => 0, 'is_active' => true]);
        $pai = Product::factory()->create(['name' => 'Bicicleta Infantil', 'stock' => 0, 'is_active' => true]);
        Product::factory()->create(['name' => 'Bicicleta Infantil Azul', 'stock' => 3, 'is_active' => true, 'parent_product_id' => $pai->id]);

        $ids = collect($this->get('/?search=bicicleta')->viewData('page')['props']['products']['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$comEstoque->id, $pai->id], $ids);
    }

    public function test_esgotado_continua_abrindo_pelo_link(): void
    {
        $esgotado = Product::factory()->create(['stock' => 0, 'is_active' => true]);

        $this->get("/produtos/{$esgotado->slug}")->assertOk();
    }

    public function test_relatorio_de_duplicados_aponta_par_sem_alterar_nada(): void
    {
        $a = Product::factory()->create(['name' => 'Bike Spinning Ergometrica Profissional Preto', 'cost_price' => 380, 'is_active' => true]);
        $b = Product::factory()->create(['name' => 'Bicicleta Ergometrica Bike Spinning Profissional Mecanica', 'cost_price' => 380, 'is_active' => true]);

        $this->artisan('catalogo:duplicados')
            ->expectsOutputToContain("#{$a->id}")
            ->expectsOutputToContain('1 pares suspeitos')
            ->assertSuccessful();

        $this->assertTrue($b->fresh()->is_active);
    }
}

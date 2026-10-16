<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Anúncio com variação mostra o menor preço (pedido 2026-10-16, ex.: Árvore de Natal). */
class MenorPrecoDaVariacaoTest extends TestCase
{
    use RefreshDatabase;

    private function arvore(): array
    {
        $pai = Product::factory()->create(['is_active' => true, 'name' => 'Árvore de Natal', 'price' => 560, 'discount_percentage' => 5, 'discount_amount' => null, 'stock' => 10]);
        $barata = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id, 'price' => 280, 'discount_percentage' => 5, 'discount_amount' => null, 'stock' => 10]);
        Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id, 'price' => 420, 'discount_percentage' => 5, 'discount_amount' => null, 'stock' => 10]);

        return [$pai, $barata];
    }

    private function cards(string $url = '/?todos=1')
    {
        return collect($this->get($url)->viewData('page')['props']['products']['data'])->keyBy('id');
    }

    public function test_card_mostra_o_preco_da_variacao_mais_barata(): void
    {
        [$pai, $barata] = $this->arvore();
        $semVariacao = Product::factory()->create(['is_active' => true, 'name' => 'Trena', 'stock' => 5]);

        $cards = $this->cards();

        $vitrine = $cards[$pai->id]['preco_vitrine'];
        $this->assertEquals($barata->fresh()->final_price, $vitrine['final_price']);
        $this->assertEquals(280.0, $vitrine['price']);
        $this->assertTrue($vitrine['a_partir_de']);
        $this->assertArrayNotHasKey('variacoes_ativas', $cards[$pai->id]);
        // Preço do próprio produto (carrinho) continua o dele.
        $this->assertEquals($pai->fresh()->final_price, $cards[$pai->id]['final_price']);
        $this->assertNull($cards[$semVariacao->id]['preco_vitrine']);
    }

    public function test_ignora_variacao_inativa_e_prefere_as_com_estoque(): void
    {
        [$pai, $barata] = $this->arvore();
        Product::factory()->create(['is_active' => false, 'parent_product_id' => $pai->id, 'price' => 10, 'stock' => 10]);
        $barata->update(['stock' => 0]);

        $this->assertEquals(420.0, $this->cards()[$pai->id]['preco_vitrine']['price']);
    }

    public function test_variacoes_com_o_mesmo_preco_nao_mostram_a_partir_de(): void
    {
        $pai = Product::factory()->create(['is_active' => true, 'price' => 50, 'discount_percentage' => null, 'discount_amount' => null, 'stock' => 3]);
        Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id, 'price' => 50, 'discount_percentage' => null, 'discount_amount' => null, 'stock' => 3]);

        $this->assertFalse($this->cards()[$pai->id]['preco_vitrine']['a_partir_de']);
    }

    public function test_filtro_e_ordem_por_preco_usam_o_menor_preco(): void
    {
        [$pai] = $this->arvore();
        $media = Product::factory()->create(['is_active' => true, 'price' => 400, 'discount_percentage' => null, 'discount_amount' => null, 'stock' => 5]);

        $this->assertSame([$pai->id, $media->id], $this->cards('/?todos=1&ordenar=menor_preco')->keys()->take(2)->all());
        $this->assertArrayHasKey($pai->id, $this->cards('/?preco=200-mais')->all());
    }
}

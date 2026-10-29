<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Produto "só do FerraKora" (pedido 2026-10-29): aparece no catálogo do
 * FerraKora e some da loja kazakora.com.
 */
class SoFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function produto(bool $soFerraKora): Product
    {
        $produto = Product::factory()->create([
            'name' => $soFerraKora ? 'Alicate Só FerraKora' : 'Alicate Das Duas Lojas', 'price' => 49.90, 'stock' => 10,
            'is_active' => true, 'so_ferrakora' => $soFerraKora, 'discount_percentage' => null, 'discount_amount' => null,
        ]);
        FerraKoraProduto::create(['product_id' => $produto->id, 'categoria' => 'Alicates e Elétrica', 'ordem' => 1, 'ativo' => true]);

        return $produto;
    }

    public function test_so_ferrakora_fica_no_catalogo_do_ferrakora(): void
    {
        $so = $this->produto(true);
        $duas = $this->produto(false);

        $ids = collect($this->getJson('/api/ferrakora/produtos')->assertOk()->json('products'))->pluck('id')->all();

        $this->assertContains('kz-'.$so->id, $ids);
        $this->assertContains('kz-'.$duas->id, $ids);
        $this->getJson('/api/ferrakora/produtos/kz-'.$so->id)->assertOk();
    }

    public function test_so_ferrakora_some_da_loja_kazakora(): void
    {
        $so = $this->produto(true);
        $duas = $this->produto(false);

        $this->get(route('produtos.ver', $so->slug))->assertNotFound();
        $this->get(route('produtos.ver', $duas->slug))->assertOk();

        $this->assertFalse($so->naKazaKora());
        $this->assertSame([$duas->id], Product::query()->naKazaKora()->pluck('id')->all());
        $this->assertStringNotContainsString($so->slug, $this->get('/sitemap.xml')->assertOk()->getContent());
    }

    public function test_so_ferrakora_nao_entra_no_carrinho_da_kazakora(): void
    {
        $so = $this->produto(true);

        $this->post(route('carrinho.adicionar'), ['product_id' => $so->id, 'quantity' => 1])->assertSessionHasErrors('product_id');

        $this->assertEmpty(session('cart', []));
    }
}

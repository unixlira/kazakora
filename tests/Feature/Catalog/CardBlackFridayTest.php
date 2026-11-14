<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\OfertaBlackFriday;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Card de Black Friday acima do preço em todos os produtos (pedido do Lira em
 * 14/11/2026). O padrão só afirma o que o checkout dá para aquele produto:
 * cupom da faixa, depois livros de brinde, depois Pix; sem nada, sem card.
 */
class CardBlackFridayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Coupon::query()->delete();
        config(['loja.cupom_faixa' => 'BLACKFRIDAY']);
    }

    private function cupom(array $campos = []): Coupon
    {
        return Coupon::create([
            'code' => 'BLACKFRIDAY', 'name' => 'Black Friday', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 5,
            'is_active' => true, 'so_kazakora' => false, 'expires_at' => now()->addDays(10)->endOfDay(), ...$campos,
        ]);
    }

    private function produto(array $campos = []): Product
    {
        return Product::factory()->create([
            'price' => 100, 'stock' => 10, 'is_active' => true, 'discount_percentage' => null, 'discount_amount' => null,
            'checkout_discount_percentage' => null, 'checkout_discount_amount' => null, ...$campos,
        ]);
    }

    public function test_cupom_da_faixa_valendo_vira_o_card_com_o_prazo(): void
    {
        $cupom = $this->cupom();
        $card = OfertaBlackFriday::padrao($this->produto(['checkout_discount_percentage' => 2]), livros: 3);

        $this->assertSame('cupom', $card['tipo']);
        $this->assertSame('Black Friday', $card['selo']);
        $this->assertSame('5% de desconto extra', $card['titulo']);
        $this->assertStringContainsString('Vale até '.$cupom->expires_at->format('d/m').'.', $card['texto']);
        $this->assertSame($cupom->expires_at->format('Y-m-d'), $card['ate']);
    }

    public function test_cupom_vencido_inativo_ou_de_outro_produto_nao_e_prometido(): void
    {
        $produto = $this->produto();

        $cupom = $this->cupom(['expires_at' => now()->subDay()]);
        $this->assertNull(OfertaBlackFriday::padrao($produto));

        $cupom->update(['expires_at' => now()->addDay(), 'is_active' => false]);
        $this->assertNull(OfertaBlackFriday::padrao($produto));

        $cupom->update(['is_active' => true, 'produtos' => [$produto->id + 999]]);
        $this->assertNull(OfertaBlackFriday::padrao($produto));

        $cupom->update(['produtos' => null, 'min_order_value' => 500]);
        $this->assertNull(OfertaBlackFriday::padrao($produto));
    }

    public function test_produto_em_oferta_do_dia_nao_promete_cupom_e_cai_no_pix(): void
    {
        $this->cupom();
        $produto = $this->produto(['checkout_discount_percentage' => 2]);
        OfertaDoDia::create(['product_id' => $produto->id, 'data' => now()->toDateString(), 'posicao' => 1, 'desconto_extra' => 10, 'preco_antes' => 100, 'preco_oferta' => 90, 'lucro_estimado' => 10]);
        $produto = $produto->fresh();

        $this->assertTrue($produto->emOferta());
        $card = OfertaBlackFriday::padrao($produto);
        $this->assertSame('pix', $card['tipo']);
        $this->assertSame('2% de desconto no Pix', $card['titulo']);
    }

    public function test_sem_cupom_vem_livros_depois_pix_depois_nada(): void
    {
        $this->assertSame('3 livros digitais de brinde', OfertaBlackFriday::padrao($this->produto(['checkout_discount_percentage' => 2]), livros: 3)['titulo']);
        $this->assertSame('1 livro digital de brinde', OfertaBlackFriday::padrao($this->produto(), livros: 1)['titulo']);
        $this->assertSame('R$ 7,00 de desconto no Pix', OfertaBlackFriday::padrao($this->produto(['checkout_discount_amount' => 7]))['titulo']);
        $this->assertNull(OfertaBlackFriday::padrao($this->produto()));
    }

    public function test_cupom_so_da_kazakora_nao_aparece_na_ferrakora(): void
    {
        $this->cupom(['so_kazakora' => true]);
        $produto = $this->produto();

        $this->assertSame('cupom', OfertaBlackFriday::padrao($produto)['tipo']);
        $this->assertNull(OfertaBlackFriday::padrao($produto, outroSite: true));
    }

    public function test_pagina_da_kazakora_e_api_da_ferrakora_levam_o_card(): void
    {
        $this->cupom();
        $produto = $this->produto(['name' => 'Alicate Universal', 'slug' => 'alicate-universal']);
        FerraKoraProduto::create(['product_id' => $produto->id, 'categoria' => 'Alicates e Elétrica', 'ordem' => 1, 'ativo' => true]);

        $this->get('/produtos/alicate-universal')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ofertaPadrao.tipo', 'cupom')
            ->where('ofertaPadrao.titulo', '5% de desconto extra'));

        $this->getJson('/api/ferrakora/produtos/kz-'.$produto->id)->assertOk()
            ->assertJsonPath('product.oferta_padrao.tipo', 'cupom')
            ->assertJsonPath('product.oferta_padrao.selo', 'Black Friday');
    }
}

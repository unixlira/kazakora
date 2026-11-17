<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\OfertaBlackFriday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PercentualCardBlackFridayTest extends TestCase
{
    use RefreshDatabase;

    public function test_converte_somente_economia_verificada_sem_somar_cupom(): void
    {
        $produto = new Product(['price' => 322.92, 'discount_percentage' => 10]);
        $card = ['oferta' => ['selo' => 'Black Friday', 'faixas' => [['rotulo' => 'Kit', 'valor' => 'R$ 32,29 de desconto + 5% do cupom'], ['rotulo' => '1', 'valor' => '5% OFF com o cupom']]]];
        $antes = $produto->getAttributes();
        $exibido = OfertaBlackFriday::comparativoPercentual($card, [$produto]);
        $this->assertSame('10% de desconto + 5% do cupom', $exibido['oferta']['faixas'][0]['valor']);
        $this->assertSame($card['oferta']['faixas'][1], $exibido['oferta']['faixas'][1]);
        $this->assertSame($antes, $produto->getAttributes());
        $this->assertSame('R$ 32,29 de desconto + 5% do cupom', $card['oferta']['faixas'][0]['valor']);
    }

    public function test_base_real_inclui_acrescimo_e_nao_usa_percentual_nominal(): void
    {
        $produto = new class(['price' => 322.92, 'discount_percentage' => 10]) extends Product {
            public function freteEmbutido(): float { return 19.52; }
        };
        $card = ['oferta' => ['selo' => 'Black Friday', 'faixas' => [['valor' => 'R$ 32,29 off + 5% do cupom']]]];
        $this->assertSame('9% off + 5% do cupom', OfertaBlackFriday::comparativoPercentual($card, [$produto])['oferta']['faixas'][0]['valor']);
        $this->assertSame($card, OfertaBlackFriday::comparativoPercentual($card, []));
    }

    public function test_percentual_inteiro_continua_dinamico_sem_arredondar_desconto_para_cima(): void
    {
        foreach ([[12.75, '12%'], [9.99, '9%'], [15, '15%']] as [$desconto, $esperado]) {
            $produto = new Product(['price' => 100, 'discount_amount' => $desconto]);
            $card = ['oferta' => ['selo' => 'Black Friday', 'faixas' => [['valor' => 'R$ '.number_format($desconto, 2, ',', '.').' de desconto + 5% do cupom']]]];
            $this->assertSame($esperado.' de desconto + 5% do cupom', OfertaBlackFriday::comparativoPercentual($card, [$produto])['oferta']['faixas'][0]['valor']);
            $outro = $card;
            $outro['oferta']['selo'] = 'Outra campanha';
            $this->assertSame($outro, OfertaBlackFriday::comparativoPercentual($outro, [$produto]));
        }
    }

    public function test_bases_ambiguas_nao_colapsam_ao_mesmo_inteiro(): void
    {
        $a = new Product(['price' => 100, 'discount_amount' => 9.50]);
        $b = new Product(['price' => 102, 'discount_amount' => 9.50]);
        $card = ['oferta' => ['selo' => 'Black Friday', 'faixas' => [['valor' => 'R$ 9,50 de desconto + 5% do cupom']]]];
        $this->assertSame($card, OfertaBlackFriday::comparativoPercentual($card, [$a, $b]));
    }

    public function test_pdp_e_api_compartilham_apresentacao_sem_salvar_no_banco(): void
    {
        $this->withoutVite();
        $pai = Product::factory()->create(['price' => 100, 'discount_percentage' => null, 'discount_amount' => null, 'is_active' => true]);
        Product::factory()->create(['parent_product_id' => $pai->id, 'price' => 200, 'discount_percentage' => 15, 'discount_amount' => null, 'is_active' => true]);
        $valor = 'R$ 30,00 de desconto + 5% do cupom';
        $conteudo = $pai->adContent()->create(['fonte' => 'claude', 'blocos' => [['titulo' => 'Teste']], 'comparativo' => ['oferta' => ['selo' => 'Black Friday', 'titulo' => 'Black Friday', 'faixas' => [['valor' => $valor]]]]]);
        \App\Modules\FerraKora\Models\FerraKoraProduto::create(['product_id' => $pai->id, 'categoria' => 'Alicates e Elétrica', 'ordem' => 1, 'ativo' => true]);
        $this->get('/produtos/'.$pai->slug)->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('product.ad_content.comparativo.oferta.faixas.0.valor', '15% de desconto + 5% do cupom'));
        $this->getJson('/api/ferrakora/produtos/kz-'.$pai->id)->assertOk()->assertJsonPath('product.conteudo.comparativo.oferta.faixas.0.valor', '15% de desconto + 5% do cupom');
        $this->assertSame($valor, $conteudo->fresh()->comparativo['oferta']['faixas'][0]['valor']);
    }

    public function test_outro_produto_e_falta_de_base_nao_inventam_percentual(): void
    {
        $produto = new Product(['price' => 200, 'discount_amount' => 30]);
        $card = ['oferta' => ['selo' => 'Black Friday', 'faixas' => [['valor' => 'R$ 30,00 de desconto'], ['valor' => 'R$ 99,00 de desconto']]]];
        $exibido = OfertaBlackFriday::comparativoPercentual($card, [$produto]);
        $this->assertSame('15% de desconto', $exibido['oferta']['faixas'][0]['valor']);
        $this->assertSame('R$ 99,00 de desconto', $exibido['oferta']['faixas'][1]['valor']);
        $this->assertNull(OfertaBlackFriday::comparativoPercentual(null, [$produto]));
    }
}

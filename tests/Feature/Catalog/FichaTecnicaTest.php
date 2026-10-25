<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\FichaTecnica;
use App\Modules\Fiscal\Models\ProductFiscalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Ficha técnica da página do produto (pedido 2026-10-24): dimensões, peso,
 * voltagem e cor; sem SKU, categoria e variação.
 */
class FichaTecnicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_monta_dimensoes_peso_voltagem_e_cor_sem_sku_categoria_variacao(): void
    {
        $produto = Product::factory()->create(['brand' => 'Acme', 'model' => 'X1', 'color' => 'Preto', 'variation' => '220V', 'sku' => 'SKU-INTERNO']);
        ProductFiscalData::create(['product_id' => $produto->id, 'peso_bruto' => 1.25, 'altura_cm' => 10, 'largura_cm' => 20.5, 'profundidade_cm' => 30]);

        $ficha = collect(FichaTecnica::linhas($produto->fresh()))->mapWithKeys(fn ($linha) => [$linha[0] => $linha[1]]);

        $this->assertSame('Acme', $ficha['Marca']);
        $this->assertSame('Preto', $ficha['Cor']);
        $this->assertSame('220V', $ficha['Voltagem']);
        $this->assertSame('30 x 20,5 x 10 cm', $ficha['Dimensões da embalagem (C x L x A)']);
        $this->assertSame('1,25 kg', $ficha['Peso com embalagem']);
        $this->assertNotContains('SKU-INTERNO', $ficha->all());
        $this->assertFalse($ficha->keys()->contains(fn ($rotulo) => in_array($rotulo, ['SKU', 'Categoria', 'Variação'], true)));
    }

    public function test_peso_abaixo_de_1kg_em_gramas_e_sem_medida_nao_mostra_linha(): void
    {
        $leve = Product::factory()->create(['variation' => null, 'color' => null]);
        ProductFiscalData::create(['product_id' => $leve->id, 'peso_bruto' => 0.35]);

        $ficha = collect(FichaTecnica::linhas($leve->fresh()))->mapWithKeys(fn ($linha) => [$linha[0] => $linha[1]]);

        $this->assertSame('350 g', $ficha['Peso com embalagem']);
        $this->assertArrayNotHasKey('Dimensões da embalagem (C x L x A)', $ficha->all());
        $this->assertArrayNotHasKey('Voltagem', $ficha->all());
        $this->assertArrayNotHasKey('Cor', $ficha->all());
    }

    public function test_voltagem_reconhece_bivolt_e_ignora_numero_solto(): void
    {
        $this->assertSame('Bivolt', FichaTecnica::voltagem(new Product(['variation' => 'Bivolt'])));
        $this->assertSame('127V', FichaTecnica::voltagem(new Product(['color' => 'Branco 127 V'])));
        $this->assertNull(FichaTecnica::voltagem(new Product(['variation' => '2200 mAh'])));
        $this->assertNull(FichaTecnica::voltagem(new Product(['variation' => 'Pack 12V'])));
    }

    public function test_variacao_sem_medida_usa_a_do_pai_e_a_pagina_leva_a_ficha(): void
    {
        $pai = Product::factory()->create(['is_active' => true, 'stock' => 3]);
        ProductFiscalData::create(['product_id' => $pai->id, 'peso_bruto' => 2, 'altura_cm' => 5, 'largura_cm' => 6, 'profundidade_cm' => 7]);
        $filha = Product::factory()->create(['is_active' => true, 'stock' => 3, 'parent_product_id' => $pai->id, 'variation' => '110V']);

        $this->get('/produtos/'.$filha->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/ProductDetailV2', false)
            ->where('product.ficha_tecnica', fn ($ficha) => collect($ficha)->contains(fn ($l) => $l[0] === 'Voltagem' && $l[1] === '110V')
                && collect($ficha)->contains(fn ($l) => $l[1] === '7 x 6 x 5 cm')
                && collect($ficha)->contains(fn ($l) => $l[1] === '2 kg'))
            ->where('variations.0.ficha_tecnica', fn ($ficha) => collect($ficha)->contains(fn ($l) => $l[0] === 'Voltagem')
                || collect($ficha)->contains(fn ($l) => $l[1] === '2 kg')));
    }

    public function test_descricao_leva_as_dimensoes_da_embalagem_no_formato_c_l_a(): void
    {
        $produto = Product::factory()->create(['is_active' => true, 'stock' => 4]);
        ProductFiscalData::create(['product_id' => $produto->id, 'peso_bruto' => 2, 'altura_cm' => 23, 'largura_cm' => 8, 'profundidade_cm' => 28]);
        $semMedida = Product::factory()->create();

        $this->assertSame('28C x 8L x 23A CM', FichaTecnica::dimensoesEmbalagem($produto->fresh()));
        $this->assertNull(FichaTecnica::dimensoesEmbalagem($semMedida->fresh()));

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/ProductDetailV2', false)
            ->where('product.dimensoes_embalagem', '28C x 8L x 23A CM'));
    }
}

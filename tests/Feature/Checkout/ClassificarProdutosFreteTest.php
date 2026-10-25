<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * frete:classificar-produtos (2026-10-25): categoria de tamanho pela sugestão
 * da tela e, fora dela, quem não cabe na caixa de referência ou não tem peso.
 */
class ClassificarProdutosFreteTest extends TestCase
{
    use RefreshDatabase;

    private function produto(?float $kg, array $lados = [20, 15, 10]): Product
    {
        $p = Product::factory()->create();
        if ($kg !== null) {
            ProductFiscalData::create([
                'product_id' => $p->id, 'ncm' => '12345678', 'origem' => 0, 'unidade_tributavel' => 'UN',
                'peso_bruto' => $kg, 'profundidade_cm' => $lados[0], 'largura_cm' => $lados[1], 'altura_cm' => $lados[2],
            ]);
        }

        return $p;
    }

    public function test_caixas_de_referencia_cabem_na_propria_categoria(): void
    {
        $caixas = DB::table('frete_categorias_pacote')->get()->keyBy('chave');

        $this->assertSame([60.0, 25.0, 20.0], [(float) $caixas['media']->ref_comprimento_cm, (float) $caixas['media']->ref_largura_cm, (float) $caixas['media']->ref_altura_cm]);
        $this->assertSame([80.0, 30.0, 30.0], [(float) $caixas['grande']->ref_comprimento_cm, (float) $caixas['grande']->ref_largura_cm, (float) $caixas['grande']->ref_altura_cm]);
        $this->assertSame(2, (int) $caixas['grande']->versao);
    }

    public function test_classifica_e_deixa_de_fora_quem_nao_cabe(): void
    {
        $pequeno = $this->produto(0.4);
        $medio = $this->produto(3, [40, 30, 20]);
        $escada = $this->produto(15, [90, 30, 10]);
        $pesado = $this->produto(25, [60, 40, 30]);
        $comprido = $this->produto(5, [130, 20, 10]);
        $semPeso = $this->produto(null);

        $this->artisan('frete:classificar-produtos')->assertSuccessful();
        $this->assertNull($pequeno->fresh()->categoria_frete, 'sem --aplicar não grava');

        $this->artisan('frete:classificar-produtos', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame('pequena', $pequeno->fresh()->categoria_frete);
        $this->assertSame('media', $medio->fresh()->categoria_frete);
        $this->assertSame('extra_grande', $escada->fresh()->categoria_frete);
        $this->assertNull($pesado->fresh()->categoria_frete);
        $this->assertNull($comprido->fresh()->categoria_frete);
        $this->assertNull($semPeso->fresh()->categoria_frete);
    }

    public function test_nao_mexe_em_quem_ja_tem_categoria_sem_refazer(): void
    {
        $p = $this->produto(0.4);
        $p->forceFill(['categoria_frete' => 'media'])->save();

        $this->artisan('frete:classificar-produtos', ['--aplicar' => true])->assertSuccessful();
        $this->assertSame('media', $p->fresh()->categoria_frete);

        $this->artisan('frete:classificar-produtos', ['--aplicar' => true, '--refazer' => true])->assertSuccessful();
        $this->assertSame('pequena', $p->fresh()->categoria_frete);
    }
}

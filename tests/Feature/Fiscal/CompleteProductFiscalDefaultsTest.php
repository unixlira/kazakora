<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompleteProductFiscalDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_completa_so_o_que_esta_vazio_e_nunca_sobrescreve(): void
    {
        $semCst = Product::factory()->create();
        ProductFiscalData::create([
            'product_id' => $semCst->id,
            'ncm' => '85044010',
            'cfop' => '5405',
            'pis_situacao_tributaria' => null,
            'cofins_situacao_tributaria' => '8',
        ]);

        $semCadastro = Product::factory()->create();

        $this->artisan('fiscal:completar-padroes --dry-run')->assertSuccessful();
        $this->assertNull($semCst->fiscalData()->first()->pis_situacao_tributaria);

        $this->artisan('fiscal:completar-padroes')->assertSuccessful();

        $fiscal = $semCst->fiscalData()->first();
        $this->assertSame('08', $fiscal->pis_situacao_tributaria);
        $this->assertSame('08', $fiscal->cofins_situacao_tributaria);
        $this->assertSame('5405', $fiscal->cfop);
        $this->assertSame('85044010', $fiscal->ncm);

        $this->assertSame('102', $semCadastro->fiscalData()->first()->icms_situacao_tributaria);
    }
}

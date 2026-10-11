<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Services\Bling\BlingClient;
use App\Services\Fiscal\NcmValidatorService;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** catalogo:fiscal-canais (2026-10-11): cadastro da Kazakora → Shopee/Bling. */
class AlinharFiscalNosCanaisTest extends TestCase
{
    use RefreshDatabase;

    private function produto(string $ncm = '84672992'): Product
    {
        $p = Product::factory()->create();
        ProductFiscalData::create(['product_id' => $p->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => $ncm, 'origem' => 2]);

        $v = Mockery::mock(NcmValidatorService::class);
        $v->shouldReceive('isValid')->andReturnUsing(fn ($n) => $n === '84672992');
        $this->app->instance(NcmValidatorService::class, $v);

        return $p;
    }

    public function test_shopee_recebe_ncm_origem_e_csosn_do_cadastro(): void
    {
        $p = $this->produto();
        $shopee = Mockery::mock(ShopeeClient::class);
        $shopee->shouldReceive('get')->andReturn(['response' => ['item_list' => [['tax_info' => ['ncm' => '84629920', 'origin' => '0', 'csosn' => '300', 'same_state_cfop' => '5102']]]]]);
        $shopee->shouldReceive('post')->once()->with('/api/v2/product/update_item', Mockery::on(fn ($b) => $b['item_id'] === 58215802445
            && $b['tax_info']['ncm'] === '84672992' && $b['tax_info']['origin'] === '2' && $b['tax_info']['csosn'] === '102'))->andReturn([]);
        $this->app->instance(ShopeeClient::class, $shopee);
        $this->app->instance(BlingClient::class, Mockery::mock(BlingClient::class));

        $this->artisan('catalogo:fiscal-canais', ['--shopee' => ["58215802445:{$p->id}"], '--aplicar' => true])->assertSuccessful();
    }

    public function test_simulacao_nao_grava_e_ncm_invalido_nao_vai(): void
    {
        $p = $this->produto('84629920');
        $shopee = Mockery::mock(ShopeeClient::class);
        $shopee->shouldNotReceive('post');
        $shopee->shouldNotReceive('get');
        $this->app->instance(ShopeeClient::class, $shopee);
        $this->app->instance(BlingClient::class, Mockery::mock(BlingClient::class));

        $this->artisan('catalogo:fiscal-canais', ['--shopee' => ["1:{$p->id}"], '--aplicar' => true])
            ->expectsOutputToContain('sem NCM válido')
            ->assertSuccessful();
    }
}

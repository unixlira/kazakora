<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\Alinhamento\ColetorCatalogo;
use App\Services\Bling\BlingClient;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** catalogo:alinhar coletar — só lê, grava o retrato (2026-10-11). */
class AlinharCatalogoColetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_coleta_as_tres_fontes_sem_escrever_nas_apis(): void
    {
        Storage::fake('local');
        Product::factory()->create(['sku' => 'KZ-1', 'name' => 'Organizador']);

        $shopee = Mockery::mock(ShopeeClient::class);
        $shopee->shouldReceive('get')->with('/api/v2/product/get_item_list', Mockery::on(fn ($q) => $q['item_status'] === 'NORMAL'))
            ->andReturn(['response' => ['item' => [['item_id' => 10]], 'has_next_page' => false]]);
        $shopee->shouldReceive('get')->with('/api/v2/product/get_item_list', Mockery::any())
            ->andReturn(['response' => ['item' => [], 'has_next_page' => false]]);
        $shopee->shouldReceive('get')->with('/api/v2/product/get_item_base_info', Mockery::any())
            ->andReturn(['response' => ['item_list' => [['item_id' => 10, 'item_name' => 'Organizador', 'item_sku' => 'KZ-1', 'has_model' => true, 'tax_info' => ['ncm' => '39249000']]]]]);
        $shopee->shouldReceive('get')->with('/api/v2/product/get_model_list', ['item_id' => 10])
            ->andReturn(['response' => ['tier_variation' => [['option_list' => [['option' => 'Branco']]]], 'model' => [['model_id' => 99, 'model_sku' => 'KZ-1', 'tier_index' => [0]]]]]);
        $shopee->shouldNotReceive('post');

        $bling = Mockery::mock(BlingClient::class);
        $bling->shouldReceive('get')->with('produtos', Mockery::any())->andReturn(['data' => [['id' => 5]]]);
        $bling->shouldReceive('get')->with('produtos/5')->andReturn(['data' => ['codigo' => 'KZ-1', 'nome' => 'Organizador', 'tributacao' => ['ncm' => '', 'origem' => 0]]]);
        $bling->shouldNotReceive('put');
        $bling->shouldNotReceive('post');

        $this->app->instance(ColetorCatalogo::class, new ColetorCatalogo($shopee, $bling));

        $this->artisan('catalogo:alinhar', ['etapa' => 'coletar'])->assertSuccessful();

        $pasta = Storage::disk('local')->get('alinhamento/ultimo.txt');
        $shopeeJson = json_decode(Storage::disk('local')->get("{$pasta}/shopee.json"), true);
        $blingJson = json_decode(Storage::disk('local')->get("{$pasta}/bling.json"), true);
        $kzJson = json_decode(Storage::disk('local')->get("{$pasta}/kazakora.json"), true);

        $this->assertSame('Branco', $shopeeJson[0]['modelos'][0]['opcao']);
        $this->assertSame('39249000', $shopeeJson[0]['tax_info']['ncm']);
        $this->assertSame('KZ-1', $blingJson[0]['codigo']);
        $this->assertSame('KZ-1', $kzJson[0]['sku']);
    }
}

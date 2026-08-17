<?php

namespace Tests\Feature\Shopee;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-17 (pedido 260817JCXFKP1R, Fernando Prado, nunca
 * importado): ShopeeDriver::autoImportProduct() fazia `exists()`-então-
 * `create()` pro SKU "SHOPEE-{item_id}" — TOCTOU real. A Shopee reentregou
 * o mesmo webhook 2x em 11s, as 2 execuções concorrentes passaram no
 * `exists()` antes de qualquer uma comitar, e a segunda estourou
 * "Duplicate entry ... for key products_sku_unique", derrubando o import
 * inteiro (o pedido nunca chegou a existir no banco). Fix: retry limitado
 * reagindo à própria constraint, nunca a um pré-check racy.
 */
class AutoImportProductSkuRaceTest extends TestCase
{
    use RefreshDatabase;

    private function connectShopee(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);
    }

    private function fakeItemDetail(): array
    {
        return [
            'response' => [
                'item_list' => [[
                    'item_id' => 58265922084,
                    'item_name' => 'Ring Light 8" Completo',
                    'price_info' => [['current_price' => 36.88]],
                    'description' => 'Ring light com tripé',
                    'stock_info_v2' => ['summary_info' => ['total_available_stock' => 69]],
                ]],
            ],
        ];
    }

    public function test_auto_import_regenerates_the_sku_when_the_clean_one_collides_for_real(): void
    {
        $this->connectShopee();
        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail())]);

        // Simula a corrida: outra execução concorrente já comitou o
        // Product com o SKU limpo um instante antes desta chegar no
        // create() — não é um `exists()` prévio, é a constraint real que
        // esta chamada precisa sobreviver.
        Product::factory()->create(['sku' => 'SHOPEE-58265922084']);

        $product = app(ShopeeDriver::class)->autoImportProduct('58265922084', 1);

        $this->assertNotNull($product);
        $this->assertNotSame('SHOPEE-58265922084', $product->sku);
        $this->assertStringStartsWith('SHOPEE-58265922084-', $product->sku);
        $this->assertSame('Ring Light 8" Completo', $product->name);
    }

    public function test_auto_import_uses_the_clean_sku_when_there_is_no_collision(): void
    {
        $this->connectShopee();
        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail())]);

        $product = app(ShopeeDriver::class)->autoImportProduct('58265922084', 1);

        $this->assertSame('SHOPEE-58265922084', $product->sku);
    }

    public function test_auto_import_still_throws_a_real_unrelated_database_error_instead_of_retrying_forever(): void
    {
        $this->connectShopee();
        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail())]);

        // Derruba uma coluna usada no insert pra forçar um erro de banco
        // genuíno, sem nenhuma relação com products_sku_unique — não pode
        // ser engolido pelo retry (que só existe pra colisão de SKU), tem
        // que subir de verdade já na 1ª tentativa.
        Schema::table('products', fn ($table) => $table->dropColumn('description'));

        $this->expectException(QueryException::class);

        app(ShopeeDriver::class)->autoImportProduct('58265922084', 1);
    }
}

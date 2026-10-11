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
 * importado): duas causas encontradas na mesma investigação.
 * (1) ShopeeDriver::autoImportProduct() fazia `exists()`-então-`create()`
 * pro SKU/slug — TOCTOU real, a Shopee reentregou o mesmo webhook 2x em
 * 11s e a 2ª execução concorrente estourou a constraint única.
 * (2) A causa de verdade DESSE pedido específico: o Product #46 pra esse
 * item Shopee já tinha sido auto-importado antes e foi EXCLUÍDO (soft
 * delete, Product usa SoftDeletes) — a linha continua ocupando sku/slug na
 * constraint do MySQL, invisível pro `exists()` (o scope do SoftDeletes
 * filtra trashed), mas bem real pro `create()`, que sempre falhava. Fix:
 * nunca mais pré-check, só reage à constraint (sku OU slug) e regenera os
 * dois juntos com sufixo aleatório.
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

    public function test_auto_import_uses_the_clean_sku_and_slug_when_there_is_no_collision(): void
    {
        $this->connectShopee();
        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail())]);

        $product = app(ShopeeDriver::class)->autoImportProduct('58265922084', 1);

        $this->assertSame('SHOPEE-58265922084', $product->sku);
        $this->assertSame('ring-light-8-completo', $product->slug);
    }

    public function test_auto_import_regenerates_sku_and_slug_together_when_they_collide_for_real(): void
    {
        $this->connectShopee();
        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail())]);

        // Simula a corrida: outra execução concorrente já comitou o
        // Product com o SKU/slug limpos um instante antes desta chegar no
        // create() — não é um `exists()` prévio, é a constraint real que
        // esta chamada precisa sobreviver.
        Product::factory()->create(['sku' => 'SHOPEE-58265922084', 'slug' => 'ring-light-8-completo']);

        $product = app(ShopeeDriver::class)->autoImportProduct('58265922084', 1);

        $this->assertNotNull($product);
        $this->assertStringStartsWith('SHOPEE-58265922084-', $product->sku);
        $this->assertStringStartsWith('ring-light-8-completo-', $product->slug);
        $this->assertSame('Ring Light 8" Completo', $product->name);
    }

    /**
     * O caso real que travou o pedido 260817JCXFKP1R: o produto colidente
     * não é um duplicado "vivo" comum — é uma exclusão deliberada (soft
     * delete) que `Product::where(...)->exists()` nunca via, mas que ainda
     * segura o slot na constraint única. Confirma que o fix não tenta
     * restaurar/reaproveitar o produto excluído — sempre cria um novo.
     */
    public function test_auto_import_creates_a_new_product_when_the_colliding_one_was_soft_deleted(): void
    {
        $this->connectShopee();
        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail())]);

        $deleted = Product::factory()->create(['sku' => 'SHOPEE-58265922084', 'slug' => 'ring-light-8-completo']);
        $deleted->delete();

        $product = app(ShopeeDriver::class)->autoImportProduct('58265922084', 1);

        $this->assertNotNull($product);
        $this->assertNotSame($deleted->id, $product->id);
        $this->assertStringStartsWith('SHOPEE-58265922084-', $product->sku);
        $this->assertTrue($deleted->fresh()->trashed(), 'o produto excluído continua excluído, nunca é restaurado');
    }

    public function test_auto_import_still_throws_a_real_unrelated_database_error_instead_of_retrying_forever(): void
    {
        $this->connectShopee();
        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail())]);

        // Derruba uma coluna usada no insert pra forçar um erro de banco
        // genuíno, sem nenhuma relação com products_sku_unique/slug_unique
        // — não pode ser engolido pelo retry, tem que subir de verdade já
        // na 1ª tentativa.
        Schema::table('products', fn ($table) => $table->dropColumn('description'));

        $this->expectException(QueryException::class);

        app(ShopeeDriver::class)->autoImportProduct('58265922084', 1);
    }
}

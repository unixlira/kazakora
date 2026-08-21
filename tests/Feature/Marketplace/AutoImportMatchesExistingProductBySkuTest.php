<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\MercadoLivreDriver;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-21: autoImportProduct() sempre criava um produto NOVO
 * (SKU sintético "SHOPEE-{id}"/"ML-{id}") pra qualquer anúncio ainda sem
 * ProductChannelListing local — mesmo quando o SKU de verdade cadastrado no
 * canal (Shopee `item_sku` / ML `SELLER_SKU`) já correspondia a um produto
 * existente no catálogo. Gerava produto duplicado com estoque desconectado
 * do produto real (a baixa de uma venda caía no duplicado, nunca no
 * produto de verdade que o usuário mantém). Casa pelo SKU real primeiro —
 * só cai pro comportamento antigo (cria produto novo) quando o canal não
 * tem SKU cadastrado pra esse anúncio.
 */
class AutoImportMatchesExistingProductBySkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_reuses_the_existing_product_when_the_real_sku_matches(): void
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

        $existing = Product::factory()->create(['sku' => 'TAB-SHO-DEF-PRE-0001', 'stock' => 12]);

        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response([
            'response' => [
                'item_list' => [[
                    'item_id' => 58265922084,
                    'item_name' => 'Tábua de Descongelar',
                    'item_sku' => 'TAB-SHO-DEF-PRE-0001',
                    'price_info' => [['current_price' => 36.88]],
                    'stock_info_v2' => ['summary_info' => ['total_available_stock' => 69]],
                ]],
            ],
        ])]);

        $product = app(ShopeeDriver::class)->autoImportProduct('58265922084', 1);

        $this->assertSame($existing->id, $product->id);
        $this->assertSame(12, $product->fresh()->stock, 'não deve mexer no estoque do produto real');
        $this->assertSame(1, Product::query()->count(), 'não deve criar produto duplicado');
        $this->assertDatabaseHas('product_channel_listings', [
            'product_id' => $existing->id,
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'external_id' => '58265922084',
        ]);
    }

    public function test_shopee_still_creates_a_new_product_when_the_channel_has_no_sku(): void
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

        Http::fake(['*/api/v2/product/get_item_base_info*' => Http::response([
            'response' => [
                'item_list' => [[
                    'item_id' => 58265922085,
                    'item_name' => 'Produto sem SKU cadastrado',
                    'price_info' => [['current_price' => 20]],
                    'stock_info_v2' => ['summary_info' => ['total_available_stock' => 5]],
                ]],
            ],
        ])]);

        $product = app(ShopeeDriver::class)->autoImportProduct('58265922085', 1);

        $this->assertSame('SHOPEE-58265922085', $product->sku);
    }

    public function test_mercado_livre_reuses_the_existing_product_when_the_real_sku_matches(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '654321',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);

        $existing = Product::factory()->create(['sku' => 'TAB-SHO-DEF-PRE-0001', 'stock' => 12]);

        Http::fake(['*/items/MLB4923941751*' => Http::response([
            'id' => 'MLB4923941751',
            'title' => 'Tábua de Descongelar',
            'price' => 36.88,
            'available_quantity' => 69,
            'attributes' => [
                ['id' => 'SELLER_SKU', 'name' => 'SKU', 'value_name' => 'TAB-SHO-DEF-PRE-0001'],
            ],
        ])]);

        $product = app(MercadoLivreDriver::class)->autoImportProduct('MLB4923941751', 1);

        $this->assertSame($existing->id, $product->id);
        $this->assertSame(12, $product->fresh()->stock, 'não deve mexer no estoque do produto real');
        $this->assertSame(1, Product::query()->count(), 'não deve criar produto duplicado');
        $this->assertDatabaseHas('product_channel_listings', [
            'product_id' => $existing->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_id' => 'MLB4923941751',
        ]);
    }
}

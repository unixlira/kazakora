<?php

namespace Tests\Feature\Marketplace;

use App\Models\MercadoLivreToken;
use App\Modules\Catalog\Jobs\CompleteImportedProductJob;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductCompletionService;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Marketplace\Drivers\MercadoLivreDriver;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\CrossChannelProductImporter;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pedido explícito 2026-10-07: venda de produto que não temos no Kazakora
 * importa o produto COMPLETO (dados, imagens, vídeo) e nunca fica de fora.
 */
class ProductImportCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function conectarMercadoLivre(): void
    {
        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 654321,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '654321',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);
    }

    private function jpeg(): string
    {
        $imagem = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($imagem);

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function anuncioComVariacao(): array
    {
        return [
            'id' => 'MLB777',
            'title' => 'Power Bank 10000mAh',
            'price' => 99.9,
            'available_quantity' => 30,
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Kora'],
                ['id' => 'MODEL', 'value_name' => 'PB-10'],
            ],
            'pictures' => [
                ['id' => 'P-ROSA', 'secure_url' => 'https://http2.mlstatic.com/rosa.jpg'],
                ['id' => 'P-PRETO', 'secure_url' => 'https://http2.mlstatic.com/preto.jpg'],
            ],
            'variations' => [
                [
                    'id' => 111,
                    'price' => 89.9,
                    'available_quantity' => 7,
                    'picture_ids' => ['P-ROSA'],
                    'attribute_combinations' => [['id' => 'COLOR', 'value_name' => 'Rosa']],
                    'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'PB-ROSA'], ['id' => 'GTIN', 'value_name' => '7890000000017']],
                ],
                [
                    'id' => 222,
                    'price' => 89.9,
                    'available_quantity' => 3,
                    'picture_ids' => ['P-PRETO'],
                    'attribute_combinations' => [['id' => 'COLOR', 'value_name' => 'Preto']],
                    'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'PB-PRETO']],
                ],
            ],
        ];
    }

    public function test_mercado_livre_reads_sku_color_and_stock_of_the_sold_variation(): void
    {
        $this->conectarMercadoLivre();
        Http::fake(['*/items/MLB777*' => Http::response($this->anuncioComVariacao())]);

        $existente = Product::factory()->create(['sku' => 'PB-PRETO', 'stock' => 5]);

        $driver = app(MercadoLivreDriver::class);

        $this->assertSame($existente->id, $driver->autoImportProduct('MLB777', 1, '222')->id, 'casa pelo SKU da variação vendida, não pelo do anúncio');

        $novo = $driver->autoImportProduct('MLB777', 1, '111');

        $this->assertNotSame($existente->id, $novo->id);
        $this->assertSame('Rosa', $novo->color);
        $this->assertSame(8, $novo->stock, 'estoque da variação + esta venda');
        $this->assertSame(['https://http2.mlstatic.com/rosa.jpg'], $driver->fetchItemImages('MLB777', '111'), 'só as fotos da cor vendida');
    }

    public function test_completion_fills_description_attributes_and_photos_from_mercado_livre(): void
    {
        $this->conectarMercadoLivre();
        Storage::fake('public');
        Http::fake([
            '*/items/MLB777/description*' => Http::response(['plain_text' => 'Carrega 2 aparelhos ao mesmo tempo.']),
            '*/items/MLB777*' => Http::response($this->anuncioComVariacao()),
            'http2.mlstatic.com/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $produto = Product::factory()->create(['sku' => 'ML-MLB777', 'description' => null, 'brand' => null, 'model' => null, 'color' => 'Rosa']);
        $produto->images()->delete();
        ProductChannelListing::create(['product_id' => $produto->id, 'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'external_id' => 'MLB777', 'external_model_id' => '111', 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED]);

        $resultado = app(ProductCompletionService::class)->complete($produto);

        $produto->refresh();
        $this->assertSame('Carrega 2 aparelhos ao mesmo tempo.', $produto->description);
        $this->assertSame('Kora', $produto->brand);
        $this->assertSame('PB-10', $produto->model);
        $this->assertSame('Rosa', $produto->color, 'nunca sobrescreve o que já existe');
        $this->assertSame(1, $resultado['images']);
        $this->assertSame(1, $produto->images()->count());
    }

    public function test_completion_downloads_the_shopee_video(): void
    {
        Storage::fake('public');
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);

        Http::fake([
            '*/api/v2/product/get_item_base_info*' => Http::response(['response' => ['item_list' => [[
                'item_id' => 555,
                'description' => 'Fonte para pets com filtro.',
                'brand' => ['original_brand_name' => 'NoBrand'],
                'image' => ['image_url_list' => ['https://cf.shopee.com.br/file/foto1']],
                'video_info' => [['video_url' => 'https://cvf.shopee.com.br/file/video.mp4', 'duration' => 21]],
            ]]]]),
            'cvf.shopee.com.br/*' => Http::response('mp4-bytes', 200, ['Content-Type' => 'video/mp4']),
            'cf.shopee.com.br/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $produto = Product::factory()->create(['description' => null, 'brand' => null, 'video_path' => null]);
        $produto->images()->delete();
        ProductChannelListing::create(['product_id' => $produto->id, 'channel' => MarketplaceAccount::CHANNEL_SHOPEE, 'external_id' => '555', 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED]);

        $resultado = app(ProductCompletionService::class)->complete($produto);

        $produto->refresh();
        $this->assertTrue($resultado['video']);
        $this->assertSame(21, $produto->video_duration_seconds);
        Storage::disk('public')->assertExists($produto->video_path);
        $this->assertSame('Fonte para pets com filtro.', $produto->description);
        $this->assertNull($produto->brand, '"NoBrand" não é marca');
    }

    public function test_tiktok_sku_missing_locally_is_imported_from_the_mercado_livre_listing(): void
    {
        $this->conectarMercadoLivre();
        Http::fake([
            '*/users/654321/items/search*' => Http::response(['results' => ['MLB900']]),
            '*/items/MLB900*' => Http::response([
                'id' => 'MLB900',
                'title' => 'Fonte Automática Para Pets',
                'price' => 120,
                'available_quantity' => 9,
                'attributes' => [],
            ]),
        ]);

        $produto = app(CrossChannelProductImporter::class)->import(MarketplaceAccount::CHANNEL_TIKTOK_SHOP, 'FONTE-PET-BR');

        $this->assertNotNull($produto);
        $this->assertSame('Fonte Automática Para Pets', $produto->name);
        $this->assertSame(9, $produto->stock, 'venda de outro canal não soma de volta no estoque do ML');
        $this->assertDatabaseHas('product_channel_listings', ['product_id' => $produto->id, 'channel' => MarketplaceAccount::CHANNEL_TIKTOK_SHOP, 'external_id' => 'FONTE-PET-BR']);
        $this->assertDatabaseHas('product_channel_listings', ['product_id' => $produto->id, 'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'external_id' => 'MLB900']);
    }

    public function test_cross_channel_never_guesses_from_a_bling_internal_id(): void
    {
        Http::fake();

        $this->assertNull(app(CrossChannelProductImporter::class)->import(MarketplaceAccount::CHANNEL_TIKTOK_SHOP, 'BLING-16716459163'));
        Http::assertNothingSent();
    }

    public function test_order_for_an_unknown_listing_queues_the_full_import_of_the_new_product_only(): void
    {
        Queue::fake([GenerateInvoiceJob::class, CompleteImportedProductJob::class]);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);

        $existente = Product::factory()->create(['sku' => 'JA-EXISTE']);

        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response(['response' => ['order_list' => [[
                'order_sn' => 'SN-NOVO-1',
                'order_status' => 'READY_TO_SHIP',
                'buyer_username' => 'comprador',
                'buyer_cpf_id' => '12345678909',
                'recipient_address' => [
                    'name' => 'Cliente Teste', 'phone' => '11999999999', 'zipcode' => '01000000',
                    'full_address' => 'Rua X, 1', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'São Paulo',
                ],
                'item_list' => [
                    ['item_id' => 111, 'model_id' => 0, 'model_quantity_purchased' => 1, 'model_discounted_price' => 50, 'item_name' => 'Produto Novo'],
                    ['item_id' => 222, 'model_id' => 0, 'model_quantity_purchased' => 1, 'model_discounted_price' => 30, 'item_name' => 'Produto Já Cadastrado'],
                ],
                'total_amount' => 80,
                'create_time' => now()->timestamp,
            ]]]]),
            '*/api/v2/product/get_item_base_info*item_id_list=111*' => Http::response(['response' => ['item_list' => [[
                'item_id' => 111, 'item_name' => 'Produto Novo', 'price_info' => [['current_price' => 50]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 4]],
            ]]]]),
            '*/api/v2/product/get_item_base_info*item_id_list=222*' => Http::response(['response' => ['item_list' => [[
                'item_id' => 222, 'item_name' => 'Produto Já Cadastrado', 'item_sku' => 'JA-EXISTE', 'price_info' => [['current_price' => 30]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 4]],
            ]]]]),
        ]);

        $data = app(ShopeeDriver::class)->importOrder('SN-NOVO-1');
        $order = app(OrderImportService::class)->importNormalized(MarketplaceAccount::CHANNEL_SHOPEE, $data, dispatchShippingConfirmation: false);

        $novo = $order->items->firstWhere('external_item_id', '111')->product;

        $this->assertNotNull($novo, 'o pedido nunca fica sem produto');
        $this->assertSame($existente->id, $order->items->firstWhere('external_item_id', '222')->product_id);
        Queue::assertPushed(CompleteImportedProductJob::class, 1);
        Queue::assertPushed(CompleteImportedProductJob::class, fn ($job) => $job->productId === $novo->id);
    }
}

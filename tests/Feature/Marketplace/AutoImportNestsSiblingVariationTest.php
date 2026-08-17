<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-17 (variações de produto, achado ao vivo
 * investigando o pedido 260817JCXFKP1R): quando autoImportProduct() cria um
 * produto novo pra uma variação (model_id) nova de um anúncio (external_id)
 * que JÁ tem outra variação mapeada — o caso real do Ring Light 8"/10",
 * pedido #376 — o produto novo nasce já vinculado como variação do
 * existente, em vez de ficar solto.
 */
class AutoImportNestsSiblingVariationTest extends TestCase
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

    private function fakeOrderDetail(string $orderSn, string $modelId): array
    {
        return [
            'response' => [
                'order_list' => [[
                    'order_sn' => $orderSn,
                    'order_status' => 'READY_TO_SHIP',
                    'buyer_username' => 'comprador',
                    'buyer_cpf_id' => '12345678909',
                    'recipient_address' => [
                        'name' => 'Cliente Teste', 'phone' => '11999999999', 'zipcode' => '01000000',
                        'full_address' => 'Rua X, 1', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'São Paulo',
                    ],
                    'item_list' => [[
                        'item_id' => 58213072403,
                        'model_id' => $modelId,
                        'model_name' => '8 Polegadas',
                        'model_quantity_purchased' => 1,
                        'model_discounted_price' => 39.90,
                        'item_name' => 'Ring Light Completo',
                    ]],
                    'total_amount' => 39.90,
                    'create_time' => now()->timestamp,
                ]],
            ],
        ];
    }

    /**
     * driver->importOrder() + importNormalized(dispatchShippingConfirmation:
     * false) em vez de OrderImportService::import() puro — evita disparar
     * ConfirmChannelShippingJob de verdade (ship_order real da Shopee, fora
     * do escopo deste teste, exigiria fakear a nota fiscal enviada também).
     */
    private function importOrder(string $orderSn): \App\Modules\Checkout\Models\Order
    {
        $data = app(ShopeeDriver::class)->importOrder($orderSn);

        return app(OrderImportService::class)->importNormalized(
            MarketplaceAccount::CHANNEL_SHOPEE,
            $data,
            dispatchShippingConfirmation: false,
        );
    }

    private function fakeItemDetail(): array
    {
        return [
            'response' => [
                'item_list' => [[
                    'item_id' => 58213072403,
                    'item_name' => 'Ring Light Completo',
                    'price_info' => [['current_price' => 39.90]],
                    'stock_info_v2' => ['summary_info' => ['total_available_stock' => 20]],
                ]],
            ],
        ];
    }

    public function test_a_new_variation_of_an_already_known_listing_is_nested_under_the_existing_product(): void
    {
        $this->connectShopee();

        // Variação "10 Polegadas" já existe e está mapeada.
        $existing = Product::factory()->create(['name' => 'Ring Light 10 Polegadas']);
        ProductChannelListing::create([
            'product_id' => $existing->id,
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => '58213072403',
            'external_model_id' => '228810643304',
            'is_enabled' => true,
        ]);

        // Pedido novo pra uma variação DIFERENTE (8 Polegadas) do MESMO anúncio.
        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response($this->fakeOrderDetail('SN-VARIACAO-1', '228810643303')),
            '*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail()),
        ]);

        $order = $this->importOrder('SN-VARIACAO-1');

        $newVariation = $order->items->first()->product;

        $this->assertNotNull($newVariation);
        $this->assertNotSame($existing->id, $newVariation->id);
        $this->assertSame($existing->id, $newVariation->fresh()->parent_product_id);
    }

    /**
     * Se o produto existente já É filho de outro (caso raro, mas possível
     * depois de vinculações manuais), a variação nova entra pro MESMO pai,
     * nunca cria uma árvore de 3 níveis.
     */
    public function test_nests_under_the_real_parent_when_the_matched_sibling_is_itself_a_child(): void
    {
        $this->connectShopee();

        $parent = Product::factory()->create(['name' => 'Ring Light (grupo)']);
        $existingSibling = Product::factory()->create(['name' => 'Ring Light 10 Polegadas', 'parent_product_id' => $parent->id]);
        ProductChannelListing::create([
            'product_id' => $existingSibling->id,
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => '58213072403',
            'external_model_id' => '228810643304',
            'is_enabled' => true,
        ]);

        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response($this->fakeOrderDetail('SN-VARIACAO-2', '228810643303')),
            '*/api/v2/product/get_item_base_info*' => Http::response($this->fakeItemDetail()),
        ]);

        $order = $this->importOrder('SN-VARIACAO-2');

        $newVariation = $order->items->first()->product;

        $this->assertSame($parent->id, $newVariation->fresh()->parent_product_id);
    }

    /**
     * Item sem model_id nenhum (anúncio sem variação) não deve tentar
     * aninhar em nada — comportamento normal, sem regressão.
     */
    public function test_a_variationless_item_is_never_nested(): void
    {
        $this->connectShopee();

        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response([
                'response' => ['order_list' => [[
                    'order_sn' => 'SN-SEM-VARIACAO',
                    'order_status' => 'READY_TO_SHIP',
                    'buyer_username' => 'comprador',
                    'buyer_cpf_id' => '12345678909',
                    'recipient_address' => [
                        'name' => 'Cliente Teste', 'phone' => '11999999999', 'zipcode' => '01000000',
                        'full_address' => 'Rua X, 1', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'São Paulo',
                    ],
                    'item_list' => [[
                        'item_id' => 999888777, 'model_quantity_purchased' => 1, 'model_discounted_price' => 19.90,
                        'item_name' => 'Produto Simples', 'model_name' => '-',
                    ]],
                    'total_amount' => 19.90,
                    'create_time' => now()->timestamp,
                ]]],
            ]),
            '*/api/v2/product/get_item_base_info*' => Http::response([
                'response' => ['item_list' => [[
                    'item_id' => 999888777, 'item_name' => 'Produto Simples',
                    'price_info' => [['current_price' => 19.90]],
                ]]],
            ]),
        ]);

        $order = $this->importOrder('SN-SEM-VARIACAO');

        $this->assertNull($order->items->first()->product->parent_product_id);
    }
}

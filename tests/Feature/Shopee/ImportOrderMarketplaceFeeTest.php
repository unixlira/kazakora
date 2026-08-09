<?php

namespace Tests\Feature\Shopee;

use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-09: taxa real da Shopee (commission_fee +
 * service_fee do escrow) pro painel de lucro líquido — antes disso o driver
 * nunca devolvia 'marketplace_fee' pra Shopee (ver comentário histórico em
 * OrderImportService, "Shopee/TikTok ainda são stubs").
 */
class ImportOrderMarketplaceFeeTest extends TestCase
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

    private function fakeOrderDetail(): array
    {
        return [
            'response' => [
                'order_list' => [[
                    'order_sn' => 'SN123',
                    'order_status' => 'COMPLETED',
                    'buyer_username' => 'comprador',
                    'buyer_cpf_id' => '12345678909',
                    'recipient_address' => [
                        'name' => 'Cliente Teste', 'phone' => '11999999999', 'zipcode' => '01000000',
                        'full_address' => 'Rua X, 1', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'São Paulo',
                    ],
                    'item_list' => [[
                        'item_id' => 111, 'model_quantity_purchased' => 1, 'model_discounted_price' => 50.0,
                        'item_name' => 'Produto Teste', 'model_name' => '-',
                    ]],
                    'total_amount' => 50.0,
                    'create_time' => now()->timestamp,
                ]],
            ],
        ];
    }

    public function test_import_order_includes_the_real_marketplace_fee_when_escrow_is_ready(): void
    {
        $this->connectShopee();

        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response($this->fakeOrderDetail()),
            '*/api/v2/payment/get_escrow_detail*' => Http::response([
                'response' => [
                    'order_income' => ['commission_fee' => 8.82, 'service_fee' => 4.98],
                ],
            ]),
        ]);

        $data = app(ShopeeDriver::class)->importOrder('SN123');

        $this->assertArrayHasKey('marketplace_fee', $data);
        $this->assertSame(13.80, $data['marketplace_fee']);
    }

    public function test_import_order_omits_marketplace_fee_when_escrow_is_not_ready_yet(): void
    {
        $this->connectShopee();

        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response($this->fakeOrderDetail()),
            '*/api/v2/payment/get_escrow_detail*' => Http::response(['error' => 'error_param', 'message' => 'escrow not ready'], 400),
        ]);

        $data = app(ShopeeDriver::class)->importOrder('SN123');

        $this->assertArrayNotHasKey('marketplace_fee', $data);
    }
}

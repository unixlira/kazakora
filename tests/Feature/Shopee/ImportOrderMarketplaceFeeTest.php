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

    /**
     * Pedido do usuário 2026-10-06: taxa = venda − repasse real, com a
     * quebra. Números do pedido real #2802.
     */
    public function test_breakdown_uses_the_real_payout_and_splits_the_components(): void
    {
        $this->connectShopee();

        Http::fake([
            '*/api/v2/payment/get_escrow_detail*' => Http::response([
                'response' => [
                    'order_income' => [
                        'commission_fee' => 11.34, 'service_fee' => 17.26, 'escrow_amount' => 61.39,
                        'actual_shipping_fee' => 13.25, 'shopee_shipping_rebate' => 13.25,
                        'pix_discount' => 4.49, 'voucher_from_seller' => 0, 'seller_discount' => 74.28,
                    ],
                ],
            ]),
        ]);

        $quebra = app(ShopeeDriver::class)->resolveFeeBreakdown('SN123', 89.99);

        $this->assertSame(28.60, $quebra['fee_amount']);
        $this->assertSame(61.39, $quebra['payout_amount']);
        $this->assertSame(11.34, $quebra['commission_fee']);
        $this->assertSame(17.26, $quebra['service_fee']);
        $this->assertSame(0.0, $quebra['shipping_fee'], 'Frete coberto pela Shopee não é custo da loja.');
        $this->assertSame(4.49, $quebra['platform_discount']);
    }

    /** Frete que a Shopee não subsidiou e cupom da loja entram na taxa. */
    public function test_breakdown_counts_unsubsidized_shipping_and_seller_voucher(): void
    {
        $this->connectShopee();

        Http::fake([
            '*/api/v2/payment/get_escrow_detail*' => Http::response([
                'response' => [
                    'order_income' => [
                        'commission_fee' => 10, 'service_fee' => 5, 'escrow_amount' => 70,
                        'actual_shipping_fee' => 12, 'shopee_shipping_rebate' => 4, 'voucher_from_seller' => 5,
                    ],
                ],
            ]),
        ]);

        $quebra = app(ShopeeDriver::class)->resolveFeeBreakdown('SN123', 100.0);

        $this->assertSame(30.0, $quebra['fee_amount']);
        $this->assertSame(8.0, $quebra['shipping_fee']);
        $this->assertSame(5.0, $quebra['seller_discount']);
    }

    /** Sem repasse fechado ainda: cai pra comissão + serviço, como antes. */
    public function test_breakdown_without_payout_falls_back_to_commission_plus_service(): void
    {
        $this->connectShopee();

        Http::fake([
            '*/api/v2/payment/get_escrow_detail*' => Http::response([
                'response' => ['order_income' => ['commission_fee' => 8.82, 'service_fee' => 4.98, 'escrow_amount' => 0]],
            ]),
        ]);

        $quebra = app(ShopeeDriver::class)->resolveFeeBreakdown('SN123', 50.0);

        $this->assertSame(13.80, $quebra['fee_amount']);
        $this->assertNull($quebra['payout_amount']);
    }
}

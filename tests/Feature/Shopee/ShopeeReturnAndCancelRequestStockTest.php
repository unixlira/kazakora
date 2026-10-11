<?php

namespace Tests\Feature\Shopee;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29 (revisão de código): TO_RETURN e IN_CANCEL da Shopee
 * viravam cancelado, e syncStatus() devolvia o estoque sem olhar o status
 * anterior — pedido já ENVIADO entrando em devolução ganhava +qty na hora,
 * com o produto ainda na mão do comprador; IN_CANCEL é só um PEDIDO de
 * cancelamento, que o vendedor pode recusar. E INVOICE_PENDING/RETRY_SHIP
 * caíam no default "aguardando pagamento" (pedido nunca importado).
 */
class ShopeeReturnAndCancelRequestStockTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->product = Product::factory()->create(['stock' => 10]);

        ProductChannelListing::create([
            'product_id' => $this->product->id,
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => 'ITEM-1',
        ]);
    }

    private function importPaidOrder(): Order
    {
        return app(OrderImportService::class)->importNormalized(MarketplaceAccount::CHANNEL_SHOPEE, [
            'external_order_id' => 'SN-'.uniqid(),
            'status' => Order::STATUS_PAID,
            'channel_status' => 'READY_TO_SHIP',
            'buyer_name' => 'Cliente Teste',
            'buyer_document' => null,
            'buyer_phone' => null,
            'buyer_email' => null,
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_complement' => null,
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'shipping_cost' => 10,
            'total' => 110,
            'items' => [
                ['external_id' => 'ITEM-1', 'external_model_id' => null, 'unit_price' => 100, 'quantity' => 2],
            ],
        ], dispatchShippingConfirmation: false);
    }

    public function test_shipped_order_going_to_return_does_not_restore_stock_yet(): void
    {
        $service = app(OrderImportService::class);
        $order = $this->importPaidOrder();
        $this->assertSame(8, $this->product->fresh()->stock);

        $service->syncStatus($order, Order::STATUS_SHIPPED, 'SHIPPED');
        $service->syncStatus($order, Order::STATUS_CANCELLED, 'TO_RETURN');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(8, $this->product->fresh()->stock, 'Produto ainda está com o comprador — nada volta ao estoque ainda.');
        $this->assertNull($order->fresh()->stock_restored_at);
    }

    public function test_paid_order_cancelled_on_the_channel_still_restores_stock(): void
    {
        $order = $this->importPaidOrder();

        app(OrderImportService::class)->syncStatus($order, Order::STATUS_CANCELLED, 'CANCELLED');

        $this->assertSame(10, $this->product->fresh()->stock);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function statusMapping(): array
    {
        return [
            'pedido de cancelamento segue pago' => ['IN_CANCEL', Order::STATUS_PAID],
            'aguardando NF-e (Brasil) é pago' => ['INVOICE_PENDING', Order::STATUS_PAID],
            'reenvio é pago' => ['RETRY_SHIP', Order::STATUS_PAID],
            'devolução continua cancelado' => ['TO_RETURN', Order::STATUS_CANCELLED],
            'não pago continua aguardando' => ['UNPAID', Order::STATUS_AWAITING_PAYMENT],
        ];
    }

    #[DataProvider('statusMapping')]
    public function test_shopee_status_mapping(string $shopeeStatus, string $expected): void
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

        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response(['response' => ['order_list' => [[
                'order_sn' => 'SN123',
                'order_status' => $shopeeStatus,
                'buyer_username' => 'comprador',
                'recipient_address' => ['name' => 'Cliente', 'zipcode' => '01000000', 'full_address' => 'Rua X', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'São Paulo'],
                'item_list' => [['item_id' => 111, 'model_quantity_purchased' => 1, 'model_discounted_price' => 50.0, 'item_name' => 'Produto', 'model_name' => '-']],
                'create_time' => now()->timestamp,
            ]]]]),
            '*/api/v2/payment/get_escrow_detail*' => Http::response(['response' => []]),
        ]);

        $data = app(ShopeeDriver::class)->importOrder('SN123');

        $this->assertSame($expected, $data['status']);
        $this->assertSame($shopeeStatus, $data['channel_status']);
    }
}

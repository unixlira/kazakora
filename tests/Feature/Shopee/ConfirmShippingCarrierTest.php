<?php

namespace Tests\Feature\Shopee;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Achado 2026-08-06: ShopeeDriver::confirmShipping() gravava um literal
 * fixo ("drop_off") como shipping_method em vez do transportador real
 * devolvido pela Shopee — pedido do usuário pra "verificar tipo de entrega
 * sempre a expresso" precisa de dado real pra verificar contra, não um
 * texto fixo. Cobre a consulta a `shipping_carrier` e o alerta defensivo
 * quando não bate com o padrão esperado (a loja só opera com Shopee
 * Express habilitado no Seller Center).
 */
class ConfirmShippingCarrierTest extends TestCase
{
    use RefreshDatabase;

    private function makeConnectedAccount(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '564186623',
            'access_token' => 'shopee-access-token',
            'refresh_token' => 'shopee-refresh-token',
            'token_expires_at' => now()->addHours(3),
            'connected_at' => now(),
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_SHOPEE,
            'external_order_id' => 'SHOPEE-ORDER-1',
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    private function fakeLogisticsCalls(string $carrier): void
    {
        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response([
                'response' => ['order_list' => [['order_sn' => 'SHOPEE-ORDER-1', 'shipping_carrier' => $carrier]]],
            ]),
            '*/api/v2/logistics/get_shipping_parameter*' => Http::response([
                'response' => ['info_needed' => ['dropoff' => ['branch_list' => [['branch_id' => 999]]]]],
            ]),
            '*/api/v2/logistics/ship_order*' => Http::response(['response' => ['order_sn' => 'SHOPEE-ORDER-1']]),
        ]);
    }

    public function test_confirm_shipping_records_the_real_carrier_name_as_shipping_method(): void
    {
        $this->makeConnectedAccount();
        $order = $this->makeOrder();
        $this->fakeLogisticsCalls('SPX Express');

        $result = app(ShopeeDriver::class)->confirmShipping($order);

        $this->assertSame('SPX Express', $result['shipping_method']);
        $this->assertSame('confirmed', $result['status']);
    }

    public function test_confirm_shipping_logs_a_warning_when_carrier_is_not_express(): void
    {
        Log::shouldReceive('channel')->with('shopee')->andReturnSelf();
        Log::shouldReceive('warning')->with('shopee.shipping_carrier.not_express', \Mockery::type('array'))->once();
        Log::shouldReceive('warning')->withAnyArgs()->zeroOrMoreTimes();
        Log::shouldReceive('info')->withAnyArgs()->zeroOrMoreTimes();
        Log::shouldReceive('error')->withAnyArgs()->zeroOrMoreTimes();

        $this->makeConnectedAccount();
        $order = $this->makeOrder();
        $this->fakeLogisticsCalls('Correios Padrão');

        $result = app(ShopeeDriver::class)->confirmShipping($order);

        $this->assertSame('Correios Padrão', $result['shipping_method']);
    }

    public function test_confirm_shipping_still_works_when_carrier_lookup_fails(): void
    {
        $this->makeConnectedAccount();
        $order = $this->makeOrder();

        Http::fake([
            '*/api/v2/order/get_order_detail*' => Http::response(['error' => 'error_param', 'message' => 'boom'], 400),
            '*/api/v2/logistics/get_shipping_parameter*' => Http::response([
                'response' => ['info_needed' => ['dropoff' => ['branch_list' => [['branch_id' => 999]]]]],
            ]),
            '*/api/v2/logistics/ship_order*' => Http::response(['response' => ['order_sn' => 'SHOPEE-ORDER-1']]),
        ]);

        $result = app(ShopeeDriver::class)->confirmShipping($order);

        $this->assertSame('drop_off', $result['shipping_method']);
        $this->assertSame('confirmed', $result['status']);
    }
}

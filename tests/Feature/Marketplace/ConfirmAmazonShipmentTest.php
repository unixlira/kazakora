<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\ConfirmAmazonShipment;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido explícito 2026-09-25: a Amazon tem que ficar "Enviado", com o
 * rastreio dos Correios, sem ninguém confirmar à mão — o Bling não repassa
 * rastreio pra lá, então a confirmação vai direto pela SP-API.
 */
class ConfirmAmazonShipmentTest extends TestCase
{
    use RefreshDatabase;

    private const NUMERO = '701-2931770-8321845';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bling.amazon_loja_id' => 206308488,
            'services.amazon.sp_api_base_url' => 'https://sellingpartnerapi.test',
        ]);
    }

    private function conectarAmazon(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'lwa-token',
            'refresh_token' => 'lwa-refresh',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function pedidoComEtiqueta(string $servico = '03298'): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => self::NUMERO,
            'shipping_name' => 'Maria', 'shipping_phone' => '1', 'shipping_zip' => '13010000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);

        ChannelShipment::create(['order_id' => $order->id, 'channel' => Order::ORIGIN_AMAZON, 'status' => ChannelShipment::STATUS_LABEL_READY]);

        CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => 'amazon', 'customer_name' => 'Maria', 'zip' => '13010000', 'street' => 'Rua',
            'number' => '1', 'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'service_code' => $servico,
            'service_label' => 'PAC (contrato)', 'weight_grams' => 900, 'dimension_format' => '2', 'content_items' => [],
            'status' => CorreiosPrePostagem::STATUS_GERADA, 'correios_id' => 'PP1', 'codigo_objeto' => 'AP539439145BR', 'qr_payload' => 'AP539439145BR',
        ]);

        return $order;
    }

    private function fakeSpApi(): void
    {
        Http::fake([
            '*/orders/v0/orders/'.self::NUMERO.'/orderItems' => Http::response(['payload' => ['OrderItems' => [
                ['OrderItemId' => '111', 'QuantityOrdered' => 4],
                ['OrderItemId' => '222', 'QuantityOrdered' => 6],
            ]]]),
            '*/orders/v0/orders/'.self::NUMERO.'/shipmentConfirmation' => Http::response(null, 204),
        ]);
    }

    public function test_confirms_the_shipment_on_amazon_with_correios_and_the_tracking_code(): void
    {
        $this->conectarAmazon();
        $this->fakeSpApi();
        $order = $this->pedidoComEtiqueta();

        dispatch_sync(new ConfirmAmazonShipment($order->id));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/shipmentConfirmation')
            && $request['marketplaceId'] === 'A2Q3Y263D00KWC'
            && $request['packageDetail']['carrierName'] === 'Correios'
            && $request['packageDetail']['shippingMethod'] === 'PAC'
            && $request['packageDetail']['trackingNumber'] === 'AP539439145BR'
            && count($request['packageDetail']['orderItems']) === 2);
        $this->assertNotNull(CorreiosPrePostagem::first()->amazon_confirmado_em);

        // Segunda vez: já confirmado, nada vai pra Amazon.
        Http::fake();
        dispatch_sync(new ConfirmAmazonShipment($order->id));
        Http::assertNothingSent();
    }

    public function test_without_sp_api_it_says_so_in_the_order_and_sends_nothing(): void
    {
        Http::fake();
        $order = $this->pedidoComEtiqueta();

        dispatch_sync(new ConfirmAmazonShipment($order->id));

        Http::assertNothingSent();
        $this->assertStringContainsString('Seller Central', (string) OrderFulfillmentEvent::where('order_id', $order->id)->latest('id')->value('message'));
    }

    public function test_pending_shipments_are_confirmed_once_the_account_is_connected(): void
    {
        Queue::fake([ConfirmAmazonShipment::class]);
        $order = $this->pedidoComEtiqueta();

        $this->artisan('amazon:confirmar-envios');
        Queue::assertNothingPushed();

        $this->conectarAmazon();
        $this->artisan('amazon:confirmar-envios');
        Queue::assertPushed(ConfirmAmazonShipment::class, fn ($job) => $job->orderId === $order->id);
    }
}

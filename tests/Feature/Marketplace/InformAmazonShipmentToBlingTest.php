<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\InformAmazonShipmentToBling;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito 2026-09-25: saiu o código de postagem dos Correios, o
 * pedido da Amazon é atualizado (via Bling) como enviado, com o rastreio.
 */
class InformAmazonShipmentToBlingTest extends TestCase
{
    use RefreshDatabase;

    private const BLING_ID = 22334455667;

    private const NUMERO_AMAZON = '701-1234567-1234567';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.amazon_loja_id' => 206308488,
            'services.bling.amazon_envio' => ['informar' => true, 'logistica_servico_id' => null, 'situacao_id' => 9],
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'token-de-teste',
            'refresh_token' => 'refresh-de-teste',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function pedidoPostado(string $statusEnvio = ChannelShipment::STATUS_LABEL_READY): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => self::NUMERO_AMAZON,
            'shipping_name' => 'Maria', 'shipping_phone' => '1', 'shipping_zip' => '13010000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);

        ChannelShipment::create(['order_id' => $order->id, 'channel' => Order::ORIGIN_AMAZON, 'status' => $statusEnvio]);

        CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => 'amazon', 'customer_name' => 'Maria', 'zip' => '13010000', 'street' => 'Rua',
            'number' => '1', 'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'service_code' => '03298',
            'service_label' => 'PAC (contrato)', 'weight_grams' => 900, 'dimension_format' => '1', 'content_items' => [],
            'status' => CorreiosPrePostagem::STATUS_GERADA, 'correios_id' => 'PP1', 'codigo_objeto' => 'AA000000001BR', 'qr_payload' => 'AA000000001BR',
        ]);

        app(\App\Services\Bling\BlingOrderService::class)->rememberOrderId(self::NUMERO_AMAZON, self::BLING_ID);

        return $order;
    }

    private function fakeBling(): void
    {
        Http::fake([
            '*/pedidos/vendas?*' => Http::response(['data' => []]),
            '*/pedidos/vendas/'.self::BLING_ID.'/situacoes/9' => Http::response([], 204),
            '*/pedidos/vendas/'.self::BLING_ID => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response(['data' => ['id' => self::BLING_ID]])
                : Http::response(['data' => [
                    'id' => self::BLING_ID, 'numeroLoja' => self::NUMERO_AMAZON, 'data' => '2026-09-25',
                    'contato' => ['id' => 1], 'itens' => [['codigo' => 'X', 'quantidade' => 10, 'valor' => 10]], 'parcelas' => [],
                    'transporte' => ['volumes' => [['id' => 555, 'servico' => '', 'codigoRastreamento' => '']]],
                ]]),
        ]);
    }

    public function test_tracking_goes_to_the_bling_order_and_its_status_changes_to_shipped(): void
    {
        $this->fakeBling();
        $order = $this->pedidoPostado();

        dispatch_sync(new InformAmazonShipmentToBling($order->id));

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request['transporte']['volumes'][0]['codigoRastreamento'] === 'AA000000001BR'
            && $request['transporte']['volumes'][0]['id'] === 555
            // O PUT do Bling substitui o pedido: os itens têm que ir junto.
            && $request['itens'][0]['quantidade'] === 10);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/situacoes/9'));
        $this->assertNotNull(CorreiosPrePostagem::first()->bling_informado_em);

        // Segunda vez: já informado, nada vai pro Bling.
        Http::fake();
        dispatch_sync(new InformAmazonShipmentToBling($order->id));
        Http::assertNothingSent();
    }

    public function test_never_overwrites_a_tracking_code_someone_put_in_bling_by_hand(): void
    {
        Http::fake([
            '*/pedidos/vendas?*' => Http::response(['data' => []]),
            '*/pedidos/vendas/'.self::BLING_ID => Http::response(['data' => [
                'id' => self::BLING_ID, 'numeroLoja' => self::NUMERO_AMAZON,
                'transporte' => ['volumes' => [['id' => 555, 'codigoRastreamento' => 'QB999999999BR']]],
            ]]),
        ]);
        $order = $this->pedidoPostado();

        dispatch_sync(new InformAmazonShipmentToBling($order->id));

        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['PUT', 'PATCH', 'POST'], true));
    }

    public function test_waits_for_the_label_before_touching_the_bling_order(): void
    {
        $this->fakeBling();
        $order = $this->pedidoPostado(ChannelShipment::STATUS_CONFIRMED);

        dispatch_sync(new InformAmazonShipmentToBling($order->id));

        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['PUT', 'PATCH'], true));
        $this->assertNull(CorreiosPrePostagem::first()->bling_informado_em);
    }
}

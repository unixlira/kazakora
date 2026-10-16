<?php

namespace Tests\Feature\Amazon;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Exceptions\MarketplaceNotConfiguredException;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * AmazonDriver pelo caminho SP-API (sem loja Amazon no Bling): importação
 * de pedido (Orders API + RDT), mapa de status, nota, envio MFN, etiqueta,
 * confirmação de envio e os stubs de anúncio. O caminho via Bling já é
 * coberto em tests/Feature/Marketplace/Amazon*.
 */
class AmazonDriverSpApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellingpartnerapi.test';

    private const PEDIDO = '701-1234567-1234567';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amazon.sp_api_base_url' => self::BASE,
            'services.amazon.marketplace_id' => 'A2Q3Y263D00KWC',
            'services.bling.amazon_loja_id' => null,
            'app.timezone' => 'America/Sao_Paulo',
        ]);
    }

    private function conectar(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'lwa-token', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
        ]);
    }

    private function driver(): AmazonDriver
    {
        return app(AmazonDriver::class);
    }

    private function itens(): array
    {
        return ['payload' => ['OrderItems' => [
            ['OrderItemId' => '111', 'SellerSKU' => 'KZ-1', 'Title' => 'Capinha', 'QuantityOrdered' => 2, 'ItemPrice' => ['Amount' => '59.80']],
            ['OrderItemId' => '222', 'SellerSKU' => '', 'QuantityOrdered' => 1, 'ItemPrice' => ['Amount' => '10']],
        ]]];
    }

    private function fakePedido(array $rdt = ['restrictedDataToken' => 'rdt'], int $rdtStatus = 200, string $estado = 'SP'): void
    {
        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/orderItems' => Http::response($this->itens()),
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/address' => Http::response(['payload' => ['ShippingAddress' => [
                'Name' => 'Maria', 'AddressLine1' => 'Rua A, 10', 'AddressLine2' => 'Bloco B', 'AddressLine3' => 'Apto 5',
                'City' => 'Campinas', 'StateOrRegion' => $estado, 'PostalCode' => '13010-000', 'Phone' => '1999999',
            ]]]),
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/buyerInfo' => Http::response(['payload' => [
                'BuyerName' => 'Maria Silva', 'BuyerEmail' => 'x@marketplace.amazon.com.br',
                'BuyerTaxInfo' => ['TaxClassifications' => [['Name' => 'CPF', 'Value' => '123.456.789-09']]],
            ]]),
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => [
                'AmazonOrderId' => self::PEDIDO, 'OrderStatus' => 'Unshipped', 'OrderTotal' => ['Amount' => '75.70'],
                'PurchaseDate' => '2026-10-16T01:30:00Z',
            ]]),
            self::BASE.'/tokens/*' => Http::response($rdt, $rdtStatus),
        ]);
    }

    public function test_channel_and_via_bling_follow_the_bling_store(): void
    {
        $this->assertSame('amazon', $this->driver()->channel());
        $this->assertFalse($this->driver()->viaBling());

        config(['services.bling.amazon_loja_id' => 206308488]);
        $this->assertTrue($this->driver()->viaBling());
    }

    public function test_import_order_maps_the_sp_api_order(): void
    {
        $this->conectar();
        $this->fakePedido();

        $dados = $this->driver()->importOrder(self::PEDIDO);

        $this->assertSame(Order::STATUS_PAID, $dados['status']);
        $this->assertSame([['external_id' => 'KZ-1', 'external_name' => 'Capinha', 'quantity' => 2, 'unit_price' => 29.9]], $dados['items']);
        $this->assertSame(59.8, $dados['subtotal']);
        $this->assertSame(15.9, $dados['shipping_cost']);
        $this->assertSame(75.7, $dados['total']);
        $this->assertSame('Maria Silva', $dados['buyer_name']);
        $this->assertSame('12345678909', $dados['buyer_document']);
        $this->assertSame('13010000', $dados['shipping_zip']);
        $this->assertSame('Rua A, 10 Bloco B', $dados['shipping_street']);
        $this->assertSame('Apto 5', $dados['shipping_complement']);
        $this->assertSame('SP', $dados['shipping_state']);
        // 01:30 UTC = 22:30 do dia anterior em São Paulo.
        $this->assertSame('2026-10-15 22:30', $dados['placed_at']->format('Y-m-d H:i'));
        $this->assertSame('America/Sao_Paulo', $dados['placed_at']->getTimezone()->getName());
    }

    public function test_without_restricted_data_role_the_import_still_works_with_fallbacks(): void
    {
        // Situação REAL de 2026-10-16: o app não tem acesso a shippingAddress/buyerInfo.
        $this->conectar();
        $this->fakePedido(['errors' => [['code' => 'InvalidInput', 'message' => 'Application does not have access']]], 400);

        $dados = $this->driver()->importOrder(self::PEDIDO);

        $this->assertSame('Comprador Amazon', $dados['buyer_name']);
        $this->assertNull($dados['buyer_document']);
        $this->assertSame('00000000', $dados['shipping_zip']);
        $this->assertSame('NA', $dados['shipping_state']);
        $this->assertCount(1, $dados['items']);
    }

    public function test_import_order_not_found_raises(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response(['payload' => null])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não encontrado na Amazon');

        $this->driver()->importOrder(self::PEDIDO);
    }

    public function test_import_order_rate_limited_raises_429_for_the_job_to_retry(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response(['errors' => [['code' => 'QuotaExceeded', 'message' => 'You exceeded your quota']]], 429)]);

        $this->expectException(AmazonException::class);
        $this->expectExceptionCode(429);

        $this->driver()->importOrder(self::PEDIDO);
    }

    /** @return array<string, array{string, string}> */
    public static function statusDaAmazon(): array
    {
        return [
            'Pending' => ['Pending', Order::STATUS_AWAITING_PAYMENT],
            'PendingAvailability' => ['PendingAvailability', Order::STATUS_AWAITING_PAYMENT],
            'Unshipped' => ['Unshipped', Order::STATUS_PAID],
            'PartiallyShipped' => ['PartiallyShipped', Order::STATUS_PAID],
            'Shipped' => ['Shipped', Order::STATUS_SHIPPED],
            'Canceled' => ['Canceled', Order::STATUS_CANCELLED],
            'Unfulfillable' => ['Unfulfillable', Order::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('statusDaAmazon')]
    public function test_order_status_map(string $amazon, string $nosso): void
    {
        $this->conectar();
        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/orderItems' => Http::response(['payload' => ['OrderItems' => []]]),
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => ['OrderStatus' => $amazon]]),
            self::BASE.'/*' => Http::response([], 403),
        ]);

        $this->assertSame($nosso, $this->driver()->importOrder(self::PEDIDO)['status']);
    }

    /** @return array<string, array{string, string}> */
    public static function estados(): array
    {
        return [
            'UF' => ['sp', 'SP'],
            'nome sem acento' => ['Parana', 'PR'],
            'nome com acento' => ['São Paulo', 'SP'],
            'Rio de Janeiro' => ['Rio de Janeiro', 'RJ'],
        ];
    }

    #[DataProvider('estados')]
    public function test_state_name_becomes_the_two_letter_uf(string $amazon, string $uf): void
    {
        $this->conectar();
        $this->fakePedido(estado: $amazon);

        $this->assertSame($uf, $this->driver()->importOrder(self::PEDIDO)['shipping_state']);
    }

    public function test_invoice_is_not_sent_by_sp_api_but_goes_with_the_package_via_bling(): void
    {
        $order = Order::create($this->dadosPedido());
        $nota = new Invoice;

        $this->expectException(MarketplaceNotConfiguredException::class);
        try {
            $this->driver()->submitInvoice($order, $nota);
        } finally {
            config(['services.bling.amazon_loja_id' => 206308488]);
            $this->assertSame('sent', $this->driver()->submitInvoice($order, $nota)['status']);

            config(['services.bling.amazon_loja_id' => null]);
            $this->conectar();
            try {
                $this->driver()->submitInvoice($order, $nota);
                $this->fail('SP-API não tem endpoint de NF-e pra MFN');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('não implementado', $e->getMessage());
            }
        }
    }

    public function test_listing_methods_are_explicit_stubs(): void
    {
        $produto = Product::factory()->create();
        $listing = new ProductChannelListing(['channel' => 'amazon', 'external_id' => 'X']);

        foreach (['publishProduct' => [$produto, $listing], 'updateStock' => [$produto, $listing], 'unpublishProduct' => [$listing]] as $metodo => $args) {
            try {
                $this->driver()->{$metodo}(...$args);
                $this->fail("{$metodo} sem conta deveria lançar");
            } catch (MarketplaceNotConfiguredException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->conectar();

        foreach (['publishProduct' => [$produto, $listing], 'updateStock' => [$produto, $listing], 'unpublishProduct' => [$listing]] as $metodo => $args) {
            try {
                $this->driver()->{$metodo}(...$args);
                $this->fail("{$metodo} deveria ser stub");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ainda não implementada', $e->getMessage());
            }
        }
    }

    private function dadosPedido(): array
    {
        return [
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => self::PEDIDO,
            'shipping_name' => 'Maria', 'shipping_phone' => '1', 'shipping_zip' => '13010000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ];
    }

    private function pedidoComProduto(bool $comMedidas = true): Order
    {
        Company::create([
            'razao_social' => 'Kazakora Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107', 'regime_tributario' => 'mei',
            'email' => 'loja@kazakora.com', 'phone' => '11999999999', 'zip' => '03187-040', 'street' => 'Rua Mogi Mirim', 'number' => '20',
            'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);

        $produto = Product::factory()->create(['sku' => 'KZ-1']);
        if ($comMedidas) {
            ProductFiscalData::create(['product_id' => $produto->id, 'peso_bruto' => 0.2, 'altura_cm' => 5, 'largura_cm' => 10, 'profundidade_cm' => 15]);
        }

        $order = Order::create($this->dadosPedido());
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'Capinha', 'product_price' => 29.9, 'quantity' => 2, 'subtotal' => 59.8]);

        return $order;
    }

    public function test_mfn_shipping_buys_the_cheapest_service_and_caches_the_label(): void
    {
        $this->conectar();
        $order = $this->pedidoComProduto();
        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/orderItems' => Http::response($this->itens()),
            self::BASE.'/mfn/v0/eligibleShippingServices' => Http::response(['payload' => ['ShippingServiceList' => [
                ['ShippingServiceId' => 'CARO', 'Rate' => ['Amount' => 30]],
                ['ShippingServiceId' => 'BARATO', 'ShippingServiceOfferId' => 'OF1', 'Rate' => ['Amount' => 12]],
            ]]]),
            self::BASE.'/mfn/v0/shipments' => Http::response(['payload' => [
                'ShipmentId' => 'SH1', 'TrackingId' => 'BR123', 'ShippingService' => ['CarrierName' => 'Correios'],
                'Label' => ['FileContents' => ['Contents' => base64_encode('%PDF-etiqueta'), 'FileType' => 'application/pdf']],
            ]]),
        ]);

        $envio = $this->driver()->confirmShipping($order);

        $this->assertSame(['external_shipment_id' => 'SH1', 'tracking_code' => 'BR123', 'shipping_method' => 'Correios', 'status' => 'confirmed'], $envio);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/mfn/v0/shipments')
            && $r['ShippingServiceId'] === 'BARATO' && $r['ShippingServiceOfferId'] === 'OF1'
            && $r['ShipmentRequestDetails']['Weight'] === ['Value' => 400.0, 'Unit' => 'g']
            && $r['ShipmentRequestDetails']['PackageDimensions'] === ['Length' => 30.0, 'Width' => 10.0, 'Height' => 5.0, 'Unit' => 'centimeters']
            && $r['ShipmentRequestDetails']['ShipFromAddress']['PostalCode'] === '03187040'
            && count($r['ShipmentRequestDetails']['ItemList']) === 2);

        ChannelShipment::create(['order_id' => $order->id, 'channel' => 'amazon', 'status' => ChannelShipment::STATUS_CONFIRMED, 'external_shipment_id' => 'SH1']);
        Http::fake();

        $etiqueta = $this->driver()->fetchLabel($order);

        $this->assertSame(['ready' => true, 'contents' => '%PDF-etiqueta', 'content_type' => 'application/pdf'], $etiqueta);
        Http::assertNothingSent();
    }

    public function test_label_after_cache_expired_is_fetched_again_from_amazon(): void
    {
        $this->conectar();
        $order = Order::create($this->dadosPedido());
        ChannelShipment::create(['order_id' => $order->id, 'channel' => 'amazon', 'status' => ChannelShipment::STATUS_CONFIRMED, 'external_shipment_id' => 'SH2']);
        Cache::flush();
        Http::fake([self::BASE.'/mfn/v0/shipments/SH2' => Http::sequence()
            ->push(['payload' => ['Label' => ['FileContents' => ['Contents' => base64_encode('PNGDATA'), 'FileType' => 'image/png']]]])
            ->push(['payload' => ['Label' => ['FileContents' => ['Contents' => '']]]]),
        ]);

        $this->assertSame(['ready' => true, 'contents' => 'PNGDATA', 'content_type' => 'application/octet-stream'], $this->driver()->fetchLabel($order));
        $this->assertFalse($this->driver()->fetchLabel($order)['ready']);
    }

    public function test_label_without_shipment_id_raises(): void
    {
        $this->conectar();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shipment_id');

        $this->driver()->fetchLabel(Order::create($this->dadosPedido()));
    }

    public function test_mfn_shipping_refuses_to_guess_missing_data(): void
    {
        $this->conectar();
        $order = $this->pedidoComProduto(comMedidas: false);
        Http::fake([self::BASE.'/orders/v0/orders/*' => Http::response($this->itens())]);

        try {
            $this->driver()->confirmShipping($order);
            $this->fail('Sem medidas deveria parar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sem peso/dimensões', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/mfn/'));
    }

    public function test_mfn_shipping_without_eligible_service_or_items_raises(): void
    {
        $this->conectar();
        $order = $this->pedidoComProduto();
        Http::fake([
            self::BASE.'/orders/v0/orders/*' => Http::sequence()->push($this->itens())->push(['payload' => ['OrderItems' => []]]),
            self::BASE.'/mfn/v0/eligibleShippingServices' => Http::response(['payload' => ['ShippingServiceList' => []]]),
        ]);

        foreach (['Nenhum serviço de envio elegível', 'não tem itens retornados'] as $mensagem) {
            try {
                $this->driver()->confirmShipping($order);
                $this->fail("Deveria lançar: {$mensagem}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($mensagem, $e->getMessage());
            }
        }
    }

    public function test_confirmar_envio_sends_correios_tracking_to_amazon(): void
    {
        $this->conectar();
        $order = Order::create($this->dadosPedido());
        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/orderItems' => Http::response($this->itens()),
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/shipmentConfirmation' => Http::response(null, 204),
        ]);

        $this->driver()->confirmarEnvio($order, 'AA123456789BR', 'PAC');

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/shipmentConfirmation')) {
                return false;
            }
            $p = $r['packageDetail'];

            return $r['marketplaceId'] === 'A2Q3Y263D00KWC' && $p['carrierCode'] === 'Other' && $p['carrierName'] === 'Correios'
                && $p['trackingNumber'] === 'AA123456789BR' && $p['shippingMethod'] === 'PAC'
                && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $p['shipDate'])
                && $p['orderItems'] === [['orderItemId' => '111', 'quantity' => 2], ['orderItemId' => '222', 'quantity' => 1]];
        });
    }

    public function test_confirmar_envio_without_items_or_without_account_raises(): void
    {
        $order = Order::create($this->dadosPedido());

        try {
            $this->driver()->confirmarEnvio($order, 'AA1BR', 'PAC');
            $this->fail('Sem conta deveria lançar');
        } catch (MarketplaceNotConfiguredException) {
            $this->addToAssertionCount(1);
        }

        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response(['payload' => ['OrderItems' => []]])]);

        $this->expectException(RuntimeException::class);
        $this->driver()->confirmarEnvio($order, 'AA1BR', 'PAC');
    }

    public function test_confirmar_envio_rejected_by_amazon_raises_for_the_job_to_retry(): void
    {
        $this->conectar();
        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/orderItems' => Http::response($this->itens()),
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/shipmentConfirmation' => Http::response(['errors' => [['code' => 'InvalidInput', 'message' => 'Order already shipped']]], 400),
        ]);

        $this->expectException(AmazonException::class);
        $this->expectExceptionCode(400);

        $this->driver()->confirmarEnvio(Order::create($this->dadosPedido()), 'AA1BR', 'PAC');
    }
}

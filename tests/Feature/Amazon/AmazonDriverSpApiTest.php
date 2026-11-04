<?php

namespace Tests\Feature\Amazon;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Exceptions\MarketplaceNotConfiguredException;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * AmazonDriver pela SP-API (o Bling não entra na Amazon desde 04/11/2026):
 * importação de pedido (Orders API + RDT), mapa de status, NF-e para a
 * Amazon, envio DBA (Ponto de Envio) com a etiqueta da Amazon, confirmação
 * de envio e os stubs de anúncio.
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

    public function test_amazon_never_goes_through_bling_even_with_the_bling_store_id(): void
    {
        $this->assertSame('amazon', $this->driver()->channel());

        config(['services.bling.amazon_loja_id' => 206308488]);
        $this->assertFalse($this->driver()->viaBling());
    }

    public function test_import_goes_to_sp_api_even_with_the_bling_store_id(): void
    {
        config(['services.bling.amazon_loja_id' => 206308488]);
        $this->conectar();
        $this->fakePedido();

        $this->assertSame(self::PEDIDO, $this->driver()->importOrder(self::PEDIDO)['external_order_id']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'bling'));
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

        // RDT recusado: lê na chamada comum (caso real do DBA em 03/11).
        $this->assertSame('Maria Silva', $dados['buyer_name']);
        $this->assertSame('12345678909', $dados['buyer_document']);
        $this->assertSame('13010000', $dados['shipping_zip']);
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

    private function pedidoComNota(): Order
    {
        $order = $this->pedidoComProduto();
        Storage::fake('local');
        Storage::disk('local')->put('nfe/3467.xml', '<nfeProc>xml</nfeProc>');
        Invoice::create([
            'order_id' => $order->id, 'company_id' => Company::first()->id, 'status' => Invoice::STATUS_AUTHORIZED,
            'chave_acesso' => '35261165604590000107550020000034671065750212', 'xml_path' => 'nfe/3467.xml',
            'numero' => 3467, 'serie' => 2, 'valor_total' => 100,
        ]);

        return $order->fresh();
    }

    public function test_invoice_goes_to_amazon_by_the_delivery_api_for_dba(): void
    {
        $this->conectar();
        $order = $this->pedidoComNota();
        Http::fake([
            self::BASE.'/delivery/2022-07-01/invoice/status*' => Http::response(['invoices' => []]),
            self::BASE.'/delivery/2022-07-01/invoice*' => Http::response([]),
        ]);

        $resultado = $this->driver()->submitInvoice($order, $order->invoice);

        $this->assertSame('sent', $resultado['status']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/delivery/2022-07-01/invoice?orderId='.self::PEDIDO)
            && $request['programType'] === 'EasyShip'
            && base64_decode($request['invoiceContent']) === '<nfeProc>xml</nfeProc>');
    }

    public function test_shipping_schedules_dba_drop_off_and_keeps_the_amazon_label_never_correios(): void
    {
        $this->conectar();
        $order = $this->pedidoComNota();
        $zip = tempnam(sys_get_temp_dir(), 'z');
        $arquivo = new \ZipArchive;
        $arquivo->open($zip, \ZipArchive::OVERWRITE);
        $arquivo->addFromString('label.pdf', '%PDF-etiqueta-tbr');
        $arquivo->close();

        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => ['AmazonOrderId' => self::PEDIDO, 'OrderStatus' => 'Unshipped']]),
            self::BASE.'/delivery/2022-07-01/invoice/status*' => Http::response(['orderInvoicingStatus' => 'Accepted']),
            self::BASE.'/easyShip/2022-03-23/timeSlot' => Http::response(['timeSlots' => [
                ['slotId' => 'COLETA', 'handoverMethod' => 'PICKUP', 'startTime' => '2026-11-05T12:00:00Z'],
                ['slotId' => 'PONTO', 'handoverMethod' => 'DROPOFF', 'startTime' => '2026-11-05T17:00:00Z', 'endTime' => '2026-11-05T21:00:00Z'],
            ]]),
            self::BASE.'/easyShip/2022-03-23/packages/bulk' => Http::response([
                'scheduledPackages' => [['scheduledPackageId' => ['amazonOrderId' => self::PEDIDO, 'packageId' => 'P1'], 'trackingDetails' => ['trackingId' => 'TBR123']]],
                'printableDocumentsUrl' => 'https://docs.amazon.test/lote.zip',
            ]),
            self::BASE.'/easyShip/2022-03-23/package*' => Http::sequence()->push([], 404)->whenEmpty(Http::response(['scheduledPackageId' => ['amazonOrderId' => self::PEDIDO, 'packageId' => 'P1'], 'trackingDetails' => ['trackingId' => 'TBR123']])),
            'https://docs.amazon.test/*' => Http::response(file_get_contents($zip)),
        ]);
        @unlink($zip);

        $envio = $this->driver()->confirmShipping($order);

        $this->assertSame('TBR123', $envio['tracking_code']);
        $this->assertSame('Amazon DBA (Ponto de Envio)', $envio['shipping_method']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/packages/bulk')
            && $request['orderScheduleDetailsList'][0]['packageDetails']['packageTimeSlot']['slotId'] === 'PONTO', 'lote com o horário de Ponto de Envio');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'correios') || str_contains($request->url(), '/mfn/'), 'sem Correios nem MFN');
        $this->assertSame(0, CorreiosPrePostagem::count());

        $etiqueta = $this->driver()->fetchLabel($order);
        $this->assertTrue($etiqueta['ready'], 'etiqueta pronta');
        $this->assertSame('%PDF-etiqueta-tbr', $etiqueta['contents']);
        $this->assertSame('application/pdf', $etiqueta['content_type']);
    }

    public function test_shipping_waits_for_amazon_to_accept_the_invoice(): void
    {
        $this->conectar();
        $order = $this->pedidoComNota();
        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => ['AmazonOrderId' => self::PEDIDO, 'OrderStatus' => 'Unshipped']]),
            self::BASE.'/easyShip/2022-03-23/package*' => Http::response([], 404),
            self::BASE.'/delivery/2022-07-01/invoice/status*' => Http::response(['orderInvoicingStatus' => 'Processing']),
        ]);

        try {
            $this->driver()->confirmShipping($order);
            $this->fail('Não pode agendar o DBA antes de a Amazon aceitar a nota.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('não aceita pela Amazon', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/timeSlot'));
        $this->assertFalse($this->driver()->fetchLabel($order)['ready']);
    }

    public function test_without_drop_off_slot_it_never_falls_back_to_pickup(): void
    {
        $this->conectar();
        $order = $this->pedidoComNota();
        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => ['AmazonOrderId' => self::PEDIDO, 'OrderStatus' => 'Unshipped']]),
            self::BASE.'/easyShip/2022-03-23/package*' => Http::response([], 404),
            self::BASE.'/delivery/2022-07-01/invoice/status*' => Http::response(['orderInvoicingStatus' => 'Accepted']),
            self::BASE.'/easyShip/2022-03-23/timeSlot' => Http::response(['timeSlots' => [
                ['slotId' => 'COLETA', 'handoverMethod' => 'PICKUP', 'startTime' => '2026-11-05T12:00:00Z'],
            ]]),
        ]);

        $this->expectExceptionMessage('Ponto de Envio');
        $this->driver()->confirmShipping($order);
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

<?php

namespace Tests\Feature\Amazon;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Exceptions\MarketplaceNotConfiguredException;
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
 * AmazonDriver direto pela SP-API (padrão desde 16/10/2026): importação de
 * pedido (Orders API v2026-01-01), mapa de status, nota, confirmação de
 * envio com carrierCode Correios e preço/estoque pela Listings Items API.
 * O caminho via Bling já é coberto em tests/Feature/Marketplace/Amazon*.
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
            'services.amazon.direto' => true,
            'app.timezone' => 'America/Sao_Paulo',
        ]);
    }

    private function conectar(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'lwa-token', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(), 'seller_id' => 'A1SELLER',
        ]);
    }

    private function driver(): AmazonDriver
    {
        return app(AmazonDriver::class);
    }

    /** Pedido real da v2026 (estrutura conferida ao vivo em 16/10/2026, dados trocados). */
    private function pedidoV2026(string $status = 'UNSHIPPED', string $estado = 'SP', array $endereco = []): array
    {
        return ['order' => [
            'orderId' => self::PEDIDO,
            'createdTime' => '2026-10-16T01:30:00Z',
            'fulfillment' => ['fulfillmentStatus' => $status, 'fulfilledBy' => 'MERCHANT'],
            'proceeds' => ['grandTotal' => ['amount' => '70.70', 'currencyCode' => 'BRL'], 'breakdowns' => [
                ['type' => 'ITEM', 'subtotal' => ['amount' => '59.8']],
                ['type' => 'SHIPPING', 'subtotal' => ['amount' => '15.9']],
            ]],
            'orderItems' => [
                ['orderItemId' => '111', 'quantityOrdered' => 2, 'product' => ['sellerSku' => 'KZ-1', 'title' => 'Capinha', 'price' => ['unitPrice' => ['amount' => '29.9']]]],
                ['orderItemId' => '222', 'quantityOrdered' => 1, 'product' => ['sellerSku' => '', 'price' => ['unitPrice' => ['amount' => '10']]]],
            ],
            'buyer' => ['buyerName' => 'Maria Silva', 'buyerEmail' => 'x@marketplace.amazon.com.br'],
            'recipient' => ['deliveryAddress' => $endereco + [
                'name' => 'Maria', 'addressLine1' => 'Rua A, 10', 'addressLine2' => 'Apto 5', 'city' => 'Campinas',
                'stateOrRegion' => $estado, 'postalCode' => '13010-000', 'phone' => '1999999', 'countryCode' => 'BR',
                'extendedFields' => ['streetName' => 'Rua A', 'streetNumber' => '10', 'complement' => 'Apto 5', 'neighborhood' => 'Centro'],
            ]],
            'tax' => ['taxRegistrations' => [
                ['entityType' => 'MERCHANT', 'taxRegistrationType' => 'CNPJ', 'taxRegistrationNumber' => '65604590000107'],
                ['entityType' => 'BUYER', 'taxRegistrationType' => 'CPF', 'taxRegistrationNumber' => '123.456.789-09'],
            ]],
        ]];
    }

    private function fakePedido(string $status = 'UNSHIPPED', string $estado = 'SP', array $endereco = []): void
    {
        Http::fake([
            self::BASE.'/orders/2026-01-01/orders/'.self::PEDIDO.'*' => Http::response($this->pedidoV2026($status, $estado, $endereco)),
        ]);
    }

    public function test_channel_and_via_bling_follow_the_bling_store(): void
    {
        $this->assertSame('amazon', $this->driver()->channel());
        $this->assertFalse($this->driver()->viaBling());

        // Loja no Bling e SP-API desconectada: Bling é a rede de segurança.
        config(['services.bling.amazon_loja_id' => 206308488]);
        $this->assertTrue($this->driver()->viaBling());

        // SP-API conectada + AMAZON_DIRETO: direto, mesmo com a loja no Bling.
        $this->conectar();
        $this->assertFalse($this->driver()->viaBling());

        // Sem AMAZON_DIRETO (homolog, ou produção antes de ligar): Bling.
        config(['services.amazon.direto' => false]);
        $this->assertTrue($this->driver()->viaBling());
    }

    public function test_without_direto_stock_and_price_never_leave_the_kazakora(): void
    {
        $this->conectar();
        config(['services.amazon.direto' => false]);
        Http::fake();

        try {
            $this->driver()->updateStock(Product::factory()->create(), new ProductChannelListing(['channel' => 'amazon', 'external_id' => 'KZ-1']));
            $this->fail('Sem AMAZON_DIRETO não pode mexer no anúncio');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AMAZON_DIRETO', $e->getMessage());
        }

        Http::assertNothingSent();
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
        $this->assertSame(70.7, $dados['total']);
        $this->assertSame(5.0, $dados['discount_amount']);
        $this->assertSame('Maria Silva', $dados['buyer_name']);
        $this->assertSame('12345678909', $dados['buyer_document']);
        $this->assertSame('13010000', $dados['shipping_zip']);
        $this->assertSame('Rua A', $dados['shipping_street']);
        $this->assertSame('10', $dados['shipping_number']);
        $this->assertSame('Centro', $dados['shipping_neighborhood']);
        $this->assertSame('Apto 5', $dados['shipping_complement']);
        $this->assertSame('SP', $dados['shipping_state']);
        // 01:30 UTC = 22:30 do dia anterior em São Paulo.
        $this->assertSame('2026-10-15 22:30', $dados['placed_at']->format('Y-m-d H:i'));
        $this->assertSame('America/Sao_Paulo', $dados['placed_at']->getTimezone()->getName());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/orders/2026-01-01/orders/'.self::PEDIDO)
            && str_contains(urldecode($r->url()), 'includedData=BUYER,RECIPIENT,TAX,FULFILLMENT,PROCEEDS'));
    }

    public function test_address_without_extended_fields_falls_back_and_never_invents_a_number(): void
    {
        $this->conectar();
        $this->fakePedido(endereco: ['extendedFields' => []]);

        $dados = $this->driver()->importOrder(self::PEDIDO);

        $this->assertSame('Rua A, 10', $dados['shipping_street']);
        $this->assertSame('S/N', $dados['shipping_number']);
        $this->assertSame('Apto 5', $dados['shipping_complement']);
    }

    public function test_import_order_not_found_raises(): void
    {
        $this->conectar();
        Http::fake([self::BASE.'/*' => Http::response(['errors' => []])]);

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
            'PENDING' => ['PENDING', Order::STATUS_AWAITING_PAYMENT],
            'PENDING_AVAILABILITY' => ['PENDING_AVAILABILITY', Order::STATUS_AWAITING_PAYMENT],
            'UNSHIPPED' => ['UNSHIPPED', Order::STATUS_PAID],
            'PARTIALLY_SHIPPED' => ['PARTIALLY_SHIPPED', Order::STATUS_PAID],
            'SHIPPED' => ['SHIPPED', Order::STATUS_SHIPPED],
            'DELIVERED' => ['DELIVERED', Order::STATUS_SHIPPED],
            'CANCELLED' => ['CANCELLED', Order::STATUS_CANCELLED],
            'UNFULFILLABLE' => ['UNFULFILLABLE', Order::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('statusDaAmazon')]
    public function test_order_status_map(string $amazon, string $nosso): void
    {
        $this->conectar();
        $this->fakePedido($amazon);

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

    public function test_invoice_goes_with_the_package_in_both_flows(): void
    {
        $order = Order::create($this->dadosPedido());
        $nota = new Invoice;

        $this->conectar();
        $this->assertSame('sent', $this->driver()->submitInvoice($order, $nota)['status']);

        config(['services.amazon.direto' => false, 'services.bling.amazon_loja_id' => 206308488]);
        $this->assertSame('sent', $this->driver()->submitInvoice($order, $nota)['status']);
    }

    public function test_listing_methods_need_the_account(): void
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
    }

    private function fakeAnuncio(array $resposta = ['status' => 'ACCEPTED', 'issues' => []]): void
    {
        Cache::flush();
        Http::fake([
            self::BASE.'/listings/2021-08-01/items/A1SELLER/KZ-1?*' => function (Request $r) use ($resposta) {
                return $r->method() === 'GET'
                    ? Http::response(['sku' => 'KZ-1', 'summaries' => [['marketplaceId' => 'A2Q3Y263D00KWC', 'productType' => 'CELLULAR_PHONE_CASE']]])
                    : Http::response($resposta);
            },
        ]);
    }

    public function test_update_stock_patches_fulfillment_availability_with_the_real_product_type(): void
    {
        $this->conectar();
        $this->fakeAnuncio();
        $produto = Product::factory()->create(['stock' => 7]);

        $this->driver()->updateStock($produto, new ProductChannelListing(['channel' => 'amazon', 'external_id' => 'KZ-1']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), 'marketplaceIds=A2Q3Y263D00KWC')
            && $r['productType'] === 'CELLULAR_PHONE_CASE'
            && $r['patches'] === [['op' => 'merge', 'path' => '/attributes/fulfillment_availability', 'value' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => 7]]]]);
    }

    public function test_publish_existing_sku_sends_price_and_stock(): void
    {
        $this->conectar();
        $this->fakeAnuncio();
        $produto = Product::factory()->create(['stock' => 3, 'price' => 99.9]);
        $listing = new ProductChannelListing(['channel' => 'amazon', 'external_id' => 'KZ-1', 'price' => 79.9]);

        $this->assertSame('KZ-1', $this->driver()->publishProduct($produto, $listing));

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'PATCH') {
                return false;
            }
            $preco = collect($r['patches'])->firstWhere('path', '/attributes/purchasable_offer')['value'][0] ?? null;

            return $preco['currency'] === 'BRL' && $preco['marketplace_id'] === 'A2Q3Y263D00KWC'
                && $preco['our_price'][0]['schedule'][0]['value_with_tax'] === 79.9
                && collect($r['patches'])->firstWhere('path', '/attributes/fulfillment_availability')['value'][0]['quantity'] === 3;
        });
    }

    public function test_publish_without_amazon_channel_price_never_falls_back_to_the_base_price(): void
    {
        $this->conectar();
        $this->fakeAnuncio();
        $produto = Product::factory()->create(['stock' => 3, 'price' => 49.99]);

        $this->driver()->publishProduct($produto, new ProductChannelListing(['channel' => 'amazon', 'external_id' => 'KZ-1']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && collect($r['patches'])->pluck('path')->all() === ['/attributes/fulfillment_availability']);
    }

    public function test_unpublish_zeroes_the_stock_instead_of_deleting(): void
    {
        $this->conectar();
        $this->fakeAnuncio();

        $this->driver()->unpublishProduct(new ProductChannelListing(['channel' => 'amazon', 'external_id' => 'KZ-1']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['patches'][0]['value'][0]['quantity'] === 0);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_new_listing_is_created_in_seller_central_and_rejected_patch_raises(): void
    {
        $this->conectar();
        $produto = Product::factory()->create();

        try {
            $this->driver()->publishProduct($produto, new ProductChannelListing(['channel' => 'amazon']));
            $this->fail('Anúncio novo deveria parar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Seller Central', $e->getMessage());
        }

        $this->fakeAnuncio(['status' => 'INVALID', 'issues' => [['severity' => 'ERROR', 'message' => 'Preço abaixo do mínimo']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Preço abaixo do mínimo');

        $this->driver()->updateStock($produto, new ProductChannelListing(['channel' => 'amazon', 'external_id' => 'KZ-1']));
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

    public function test_label_is_the_correios_one_and_never_buys_amazon_shipping(): void
    {
        $this->conectar();
        Http::fake();

        $this->assertFalse($this->driver()->fetchLabel(Order::create($this->dadosPedido()))['ready']);
        Http::assertNothingSent();
    }

    public function test_direct_shipping_uses_the_store_correios_and_sends_the_tracking_to_amazon_not_to_bling(): void
    {
        $this->conectar();
        config(['services.bling.amazon_loja_id' => 206308488]);
        \Illuminate\Support\Facades\Queue::fake();
        // Pagamento confirmado na Amazon (PagamentoAmazon segura a etiqueta sem isso).
        Http::fake([self::BASE.'/orders/v0/orders/*' => Http::response(['payload' => ['OrderStatus' => 'Unshipped']])]);
        $order = Order::create($this->dadosPedido());

        $prePostagem = new \App\Modules\Marketplace\Models\CorreiosPrePostagem(['correios_id' => 'PP1', 'codigo_objeto' => 'AA123456789BR', 'service_code' => '03298']);
        $correios = \Mockery::mock(\App\Modules\Marketplace\Support\CorreiosAutoShipping::class);
        $correios->shouldReceive('confirm')->once()->andReturn($prePostagem);
        $correios->shouldNotReceive('geradaPara');
        $this->app->instance(\App\Modules\Marketplace\Support\CorreiosAutoShipping::class, $correios);

        $envio = $this->driver()->confirmShipping($order);

        $this->assertSame('AA123456789BR', $envio['tracking_code']);
        $this->assertSame('confirmed', $envio['status']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ConfirmAmazonShipment::class);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\InformAmazonShipmentToBling::class);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'bling'));
    }

    public function test_confirmar_envio_sends_correios_tracking_to_amazon(): void
    {
        $this->conectar();
        $order = Order::create($this->dadosPedido());
        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/shipmentConfirmation' => Http::response(null, 204),
            self::BASE.'/orders/2026-01-01/orders/'.self::PEDIDO.'*' => Http::response($this->pedidoV2026()),
        ]);

        $this->driver()->confirmarEnvio($order, ' aa 123456789br ', 'PAC');

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/shipmentConfirmation')) {
                return false;
            }
            $p = $r['packageDetail'];

            // "Correios" da lista oficial (não "Other"): é o que dá link de rastreio ao comprador.
            return $r['marketplaceId'] === 'A2Q3Y263D00KWC' && $p['carrierCode'] === 'Correios' && ! isset($p['carrierName'])
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
        Http::fake([self::BASE.'/*' => Http::response(['order' => ['orderId' => self::PEDIDO, 'orderItems' => []]])]);

        $this->expectException(RuntimeException::class);
        $this->driver()->confirmarEnvio($order, 'AA1BR', 'PAC');
    }

    public function test_confirmar_envio_rejected_by_amazon_raises_for_the_job_to_retry(): void
    {
        $this->conectar();
        Http::fake([
            self::BASE.'/orders/2026-01-01/orders/'.self::PEDIDO.'*' => Http::response($this->pedidoV2026()),
            self::BASE.'/orders/v0/orders/'.self::PEDIDO.'/shipmentConfirmation' => Http::response(['errors' => [['code' => 'InvalidInput', 'message' => 'Order already shipped']]], 400),
        ]);

        $this->expectException(AmazonException::class);
        $this->expectExceptionCode(400);

        $this->driver()->confirmarEnvio(Order::create($this->dadosPedido()), 'AA1BR', 'PAC');
    }
}

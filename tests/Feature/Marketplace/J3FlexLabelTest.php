<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\LabelFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Venda Flex dentro da área da J3 sai com a etiqueta da J3; fora dela (ou
 * com a J3 desligada ou fora do ar) continua a etiqueta do Mercado Livre.
 */
class J3FlexLabelTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.j3tms.com.br';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.print_agent.auto_print_since' => now()->subYear()->toDateTimeString(),
            'services.j3.enabled' => true,
            'services.j3.api_key' => 'chave-teste',
            'services.j3.base_url' => self::API,
        ]);
    }

    public function test_flex_inside_the_j3_area_prints_the_j3_label(): void
    {
        $shipment = $this->makeShipment();
        $this->mockDriver();
        $j3Pdf = self::pdf('J3');

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt', 'expiresIn' => '24h', 'tokenType' => 'Bearer']),
            self::API.'/api/regions/coverage/04567000' => Http::response(['inCoverage' => true]),
            self::API.'/api/orders/shipping-integration/*' => Http::response([], 404),
            self::API.'/api/orders' => Http::response([
                'success' => true,
                'logisticType' => 'flex',
                'orderCode' => 'ORD-1',
                'trackingNumber' => 'J32612345678',
                'stampUrl' => 'https://arquivos.j3/etiqueta.pdf',
            ], 201),
            'https://arquivos.j3/etiqueta.pdf' => Http::response($j3Pdf),
        ]);

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));

        $fresh = $shipment->fresh();
        $this->assertSame('J32612345678', $fresh->j3_tracking_number);
        $this->assertSame('ORD-1', $fresh->j3_order_code);
        $this->assertSame($j3Pdf, Storage::disk('local')->get($fresh->raw_label_path));
        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $shipment->order_id,
            'step' => 'label_generated',
            'message' => 'Etiqueta da J3 emitida (rastreio J32612345678)',
        ]);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::API.'/api/orders') {
                return false;
            }

            $pacote = $request['order']['packages'][0];

            return $request->hasHeader('Authorization', 'Bearer jwt')
                && $request['ecommerce'] === 'MercadoLivre'
                && $request['order']['shippingIntegrationId'] === '47981054232'
                && $request['order']['shippingAddress']['addressZipCode'] === '04567-000'
                && $pacote['weightInGrams'] === 900
                && $pacote['itemValueInCents'] === 12990;
        });
    }

    public function test_flex_outside_the_j3_area_keeps_the_mercado_livre_label(): void
    {
        $shipment = $this->makeShipment();
        $mlPdf = $this->mockDriver();

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt']),
            self::API.'/api/regions/coverage/*' => Http::response(['inCoverage' => false]),
        ]);

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));

        $fresh = $shipment->fresh();
        $this->assertNull($fresh->j3_tracking_number);
        $this->assertSame($mlPdf, Storage::disk('local')->get($fresh->label_path));
        Http::assertNotSent(fn (Request $request) => $request->url() === self::API.'/api/orders');
    }

    public function test_j3_down_falls_back_to_the_mercado_livre_label(): void
    {
        $shipment = $this->makeShipment();
        $mlPdf = $this->mockDriver();

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt']),
            self::API.'/api/regions/coverage/*' => Http::response(['inCoverage' => true]),
            self::API.'/api/orders/shipping-integration/*' => Http::response([], 404),
            self::API.'/api/orders' => Http::response(['success' => false, 'error' => 'CEP não está na área de cobertura'], 422),
        ]);

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));
        $this->assertSame($mlPdf, Storage::disk('local')->get($shipment->fresh()->label_path));
    }

    public function test_disabled_j3_never_calls_the_api(): void
    {
        config(['services.j3.enabled' => false]);
        $shipment = $this->makeShipment();
        $mlPdf = $this->mockDriver();
        Http::fake();

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));
        $this->assertSame($mlPdf, Storage::disk('local')->get($shipment->fresh()->label_path));
        Http::assertNothingSent();
    }

    public function test_a_sale_that_is_not_flex_never_calls_the_api(): void
    {
        $shipment = $this->makeShipment(metodo: ChannelShipment::METHOD_DROP_OFF);
        $this->mockDriver();
        Http::fake();

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));
        $this->assertNull($shipment->fresh()->j3_tracking_number);
        Http::assertNothingSent();
    }

    /** Reimpressão: o pedido já existe na J3, só busca a etiqueta de novo. */
    public function test_reprint_reuses_the_j3_order_instead_of_creating_another(): void
    {
        $shipment = $this->makeShipment();
        $shipment->forceFill(['j3_tracking_number' => 'J32612345678'])->save();
        $this->mockDriver();

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt']),
            self::API.'/api/orders/stamps/J32612345678' => Http::response(['orderId' => 'x', 'trackingNumber' => 'J32612345678', 'stampUrl' => 'https://arquivos.j3/etiqueta.pdf']),
            'https://arquivos.j3/etiqueta.pdf' => Http::response(self::pdf('J3')),
        ]);

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));
        Http::assertNotSent(fn (Request $request) => $request->url() === self::API.'/api/orders');
    }

    public function test_an_expired_token_is_renewed_once(): void
    {
        $shipment = $this->makeShipment();
        $this->mockDriver();
        $cobertura = 0;

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt']),
            self::API.'/api/regions/coverage/*' => function () use (&$cobertura) {
                return ++$cobertura === 1 ? Http::response([], 401) : Http::response(['inCoverage' => false]);
            },
        ]);

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));
        $this->assertSame(2, $cobertura);
    }

    private function makeShipment(string $metodo = ChannelShipment::METHOD_FLEX): ChannelShipment
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-1',
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '04567-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '10',
            'shipping_neighborhood' => 'Brooklin',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 129.90,
            'total' => 129.90,
        ]);

        $produto = Product::factory()->create(['sku' => 'KZ-1']);
        ProductFiscalData::create(['product_id' => $produto->id, 'peso_bruto' => 0.45, 'altura_cm' => 10, 'largura_cm' => 12, 'profundidade_cm' => 9]);

        $order->items()->create([
            'product_id' => $produto->id,
            'product_name' => 'Produto teste',
            'product_price' => 64.95,
            'quantity' => 2,
            'subtotal' => 129.90,
        ]);

        return ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_shipment_id' => '47981054232',
            'shipping_method' => $metodo,
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    /** Driver do ML com a etiqueta pronta; devolve o PDF que ele entrega. */
    private function mockDriver(): string
    {
        $pdf = self::pdf('ML');

        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('fetchLabel')->once()->andReturn(['ready' => true, 'contents' => $pdf, 'content_type' => 'application/pdf']);
        $driver->shouldReceive('confirmShipping')->zeroOrMoreTimes()->andReturn(['tracking_code' => null]);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with(MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->atLeast()->once()->andReturn($driver);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        return $pdf;
    }

    private static function pdf(string $marca): string
    {
        return "%PDF-1.4\n% {$marca}\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }
}

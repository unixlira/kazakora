<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\LojaShippingDriver;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Marketplace\Support\ChannelShippingService;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Modules\Marketplace\Support\LabelFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Pedido dos sites (KazaKora, FerraKora, Beauty Kora) com frete grátis no
 * perímetro da Entrega Expressa e na área da J3 sai pela J3 com a etiqueta
 * dela; fora disso, Correios (2026-10-21).
 */
class J3SiteShippingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.j3tms.com.br';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        config([
            'services.print_agent.auto_print_since' => now()->subYear()->toDateTimeString(),
            'services.j3.site' => true,
            'services.j3.api_key' => 'chave-teste',
            'services.j3.base_url' => self::API,
        ]);
    }

    public function test_ferrakora_order_inside_the_perimeter_ships_with_the_j3_label(): void
    {
        $order = $this->pedidoDoSite(site: Order::SITE_FERRAKORA);
        $this->semCorreios();
        $pdf = self::pdf('J3');

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt']),
            self::API.'/api/regions/coverage/04567000' => Http::response(['inCoverage' => true]),
            self::API.'/api/orders/shipping-integration/*' => Http::response([], 404),
            self::API.'/api/orders' => Http::response(['success' => true, 'trackingNumber' => 'J3SITE1', 'orderCode' => 'ORD-9', 'stampUrl' => 'https://arquivos.j3/e.pdf'], 201),
            self::API.'/api/orders/stamps/J3SITE1' => Http::response(['stampUrl' => 'https://arquivos.j3/e.pdf']),
            'https://arquivos.j3/e.pdf' => Http::response($pdf),
        ]);

        $shipment = app(ChannelShippingService::class)->confirm($order);

        $this->assertSame(LojaShippingDriver::METODO_J3, $shipment->shipping_method);
        $this->assertSame('J3SITE1', $shipment->tracking_code);
        $this->assertSame('J3SITE1', $shipment->fresh()->j3_tracking_number);
        $this->assertSame('ORD-9', $shipment->fresh()->j3_order_code);

        Http::assertSent(function (Request $request) use ($order) {
            if ($request->url() !== self::API.'/api/orders') {
                return false;
            }

            $corpo = $request->data();

            return ! array_key_exists('ecommerce', $corpo)
                && $corpo['order']['shippingIntegrationId'] === $order->external_order_id
                && $corpo['order']['nf'] === '3118'
                && $corpo['order']['notes'] === "Pedido FerraKora #{$order->id}";
        });

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));

        $job = PrintJob::query()->where('order_id', $order->id)->sole();
        $this->assertSame($pdf, Storage::disk('local')->get($shipment->fresh()->raw_label_path));
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($job->label_path));
        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $order->id,
            'message' => 'Etiqueta da J3 emitida (rastreio J3SITE1)',
        ]);
    }

    /** Retry depois de o POST ter passado: reaproveita o pedido, nunca cria outro. */
    public function test_retry_reuses_the_order_already_created_at_j3(): void
    {
        $order = $this->pedidoDoSite();
        $this->semCorreios();

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt']),
            self::API.'/api/regions/coverage/*' => Http::response(['inCoverage' => true]),
            self::API.'/api/orders/shipping-integration/*' => Http::response(['trackingNumber' => 'J3SITE1', 'orderCode' => 'ORD-9']),
        ]);

        $shipment = app(ChannelShippingService::class)->confirm($order);

        $this->assertSame('J3SITE1', $shipment->tracking_code);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::API.'/api/orders');
    }

    public function test_outside_the_perimeter_stays_with_correios(): void
    {
        $order = $this->pedidoDoSite(cep: '13010-000');
        $this->correiosUsado($order);
        Http::fake();

        $this->assertSame('Correios PAC', app(LojaShippingDriver::class)->confirmShipping($order)['shipping_method']);
        Http::assertNothingSent();
    }

    public function test_cep_outside_the_j3_area_stays_with_correios(): void
    {
        $order = $this->pedidoDoSite();
        $this->correiosUsado($order);

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt']),
            self::API.'/api/regions/coverage/*' => Http::response(['inCoverage' => false]),
        ]);

        $this->assertSame('Correios PAC', app(LojaShippingDriver::class)->confirmShipping($order)['shipping_method']);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::API.'/api/orders');
    }

    /** Quem pagou SEDEX ou outra transportadora leva o que pagou. */
    public function test_paid_shipping_stays_with_what_the_customer_paid_for(): void
    {
        $order = $this->pedidoDoSite();
        $order->forceFill(['shipping_cost' => 19.90])->save();
        $this->correiosUsado($order);
        Http::fake();

        app(LojaShippingDriver::class)->confirmShipping($order);
        Http::assertNothingSent();
    }

    public function test_switched_off_never_calls_the_j3(): void
    {
        config(['services.j3.site' => false]);
        $order = $this->pedidoDoSite();
        $this->correiosUsado($order);
        Http::fake();

        app(LojaShippingDriver::class)->confirmShipping($order);
        Http::assertNothingSent();
    }

    /** POST falhou: tenta de novo depois, sem abrir Correios por cima. */
    public function test_j3_error_while_creating_retries_instead_of_using_correios(): void
    {
        $order = $this->pedidoDoSite();
        $this->semCorreios();

        Http::fake([
            self::API.'/api/auth/changeKey*' => Http::response(['accessToken' => 'jwt']),
            self::API.'/api/regions/coverage/*' => Http::response(['inCoverage' => true]),
            self::API.'/api/orders/shipping-integration/*' => Http::response([], 404),
            self::API.'/api/orders' => Http::response(['message' => 'Bad Gateway'], 502),
        ]);

        $this->expectException(RuntimeException::class);

        app(LojaShippingDriver::class)->confirmShipping($order);
    }

    private function pedidoDoSite(string $cep = '04567-000', ?string $site = null): Order
    {
        $cliente = User::factory()->create(['cpf' => '123.456.789-09', 'email' => 'cliente@example.com']);
        $produto = Product::factory()->create(['sku' => 'KZ-1']);
        ProductFiscalData::create(['product_id' => $produto->id, 'peso_bruto' => 0.45, 'altura_cm' => 10, 'largura_cm' => 12, 'profundidade_cm' => 9]);

        $order = Order::create([
            'user_id' => $cliente->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'site_origem' => $site,
            'external_order_id' => '261021ABC123',
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => $cep,
            'shipping_street' => 'Rua X',
            'shipping_number' => '10',
            'shipping_neighborhood' => 'Brooklin',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'shipping_cost' => 0,
            'subtotal' => 129.90,
            'total' => 129.90,
        ]);

        $order->items()->create([
            'product_id' => $produto->id,
            'product_name' => 'Produto teste',
            'product_price' => 129.90,
            'quantity' => 1,
            'subtotal' => 129.90,
        ]);

        Invoice::create([
            'order_id' => $order->id,
            'origem' => Invoice::ORIGEM_PEDIDO,
            'status' => Invoice::STATUS_AUTHORIZED,
            'ambiente' => Invoice::AMBIENTE_PRODUCAO,
            'serie' => 2,
            'numero' => 3118,
            'valor_total' => 129.90,
            'chave_acesso' => '35261065604590000107550020000031181569237956',
        ]);

        return $order;
    }

    private function semCorreios(): void
    {
        $correios = Mockery::mock(CorreiosAutoShipping::class);
        $correios->shouldReceive('geradaPara')->andReturnNull();
        $correios->shouldNotReceive('confirm');
        $this->app->instance(CorreiosAutoShipping::class, $correios);
    }

    private function correiosUsado(Order $order): void
    {
        $correios = Mockery::mock(CorreiosAutoShipping::class);
        $correios->shouldReceive('geradaPara')->andReturnNull();
        $correios->shouldReceive('confirm')->once()->andReturn(new \App\Modules\Marketplace\Models\CorreiosPrePostagem([
            'order_id' => $order->id, 'correios_id' => 'PP1', 'codigo_objeto' => 'AE1BR', 'service_label' => 'PAC',
        ]));
        $this->app->instance(CorreiosAutoShipping::class, $correios);
    }

    private static function pdf(string $marca): string
    {
        return "%PDF-1.4\n% {$marca}\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }
}

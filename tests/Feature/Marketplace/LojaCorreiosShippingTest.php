<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Checkout\Services\CorreiosFreightQuoteService;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\ChannelShippingService;
use App\Modules\Marketplace\Support\LabelFetchService;
use App\Services\Correios\CorreiosPrePostagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Achado real 2026-10-17 (#3205, primeiro pedido pago do site): NF-e
 * autorizada e nenhuma etiqueta — o site não tinha caminho de envio. Agora
 * segue o da Amazon: pré-postagem dos Correios com a chave da nota, envio
 * confirmado e etiqueta na fila do KoraSync.
 */
class LojaCorreiosShippingTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35261065604590000107550020000031181569237956';

    protected function setUp(): void
    {
        parent::setUp();

        Company::create([
            'razao_social' => 'Kazakora', 'cnpj' => '65604590000107', 'regime_tributario' => 'mei',
            'zip' => '01000-000', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Centro',
            'city' => 'São Paulo', 'state' => 'SP',
        ]);
    }

    private function pedidoDoSite(): Order
    {
        $cliente = User::factory()->create(['cpf' => '123.456.789-09', 'email' => 'cliente@example.com']);
        $produto = Product::factory()->create(['sku' => 'KZ-BOMBA']);
        ProductFiscalData::create(['product_id' => $produto->id, 'peso_bruto' => 0.45, 'altura_cm' => 10, 'largura_cm' => 12, 'profundidade_cm' => 9]);

        $order = Order::create([
            'user_id' => $cliente->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Fabiana Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '03187-040',
            'shipping_street' => 'Rua Mogi Mirim',
            'shipping_number' => '20',
            'shipping_neighborhood' => 'Vila Bertioga',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 22.35,
            'total' => 22.35,
        ]);

        $order->items()->create([
            'product_id' => $produto->id,
            'product_name' => $produto->name,
            'product_price' => 22.35,
            'quantity' => 1,
            'subtotal' => 22.35,
        ]);

        Invoice::create([
            'order_id' => $order->id,
            'origem' => Invoice::ORIGEM_PEDIDO,
            'status' => Invoice::STATUS_AUTHORIZED,
            'ambiente' => Invoice::AMBIENTE_PRODUCAO,
            'serie' => 2,
            'numero' => 3118,
            'valor_total' => 22.35,
            'chave_acesso' => self::CHAVE,
        ]);

        return $order;
    }

    private function fakeCorreios(): Mockery\MockInterface
    {
        $precos = Mockery::mock(CorreiosFreightQuoteService::class);
        $precos->shouldReceive('priceFor')->andReturnUsing(fn (string $servico) => $servico === CorreiosPrePostagemService::SERVICO_PAC ? 14.0 : 11.15);
        $this->app->instance(CorreiosFreightQuoteService::class, $precos);

        $correios = Mockery::mock(CorreiosPrePostagemService::class);
        $this->app->instance(CorreiosPrePostagemService::class, $correios);

        return $correios;
    }

    public function test_site_order_gets_correios_label_in_the_korasync_queue(): void
    {
        Queue::fake();
        Storage::fake('local');
        $order = $this->pedidoDoSite();

        $correios = $this->fakeCorreios();
        // Pedido do site não grava CPF/e-mail no pedido: vêm do cadastro.
        $correios->shouldReceive('create')->once()->withArgs(fn (array $input) => $input['invoice']['chave'] === self::CHAVE
            && $input['customer']['document'] === '123.456.789-09'
            && $input['customer']['email'] === 'cliente@example.com'
        )->andReturn(['id' => 'PP52', 'codigoObjeto' => 'AE039685920BR']);

        $shipment = app(ChannelShippingService::class)->confirm($order);

        $this->assertSame(Order::ORIGIN_STORE, $shipment->channel);
        $this->assertSame(ChannelShipment::STATUS_CONFIRMED, $shipment->status);
        $this->assertSame('AE039685920BR', $shipment->tracking_code);
        Queue::assertPushed(CheckShipmentLabelJob::class);

        $this->assertTrue(app(LabelFetchService::class)->attempt($shipment->fresh()));

        $job = PrintJob::query()->where('order_id', $order->id)->sole();
        $this->assertSame(PrintJob::STATUS_QUEUED, $job->status);
        $this->assertSame(Order::ORIGIN_STORE, $job->channel);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($job->label_path));
    }

    public function test_site_is_a_shipping_driver_but_not_a_marketplace_channel(): void
    {
        $manager = app(MarketplaceDriverManager::class);

        $this->assertSame(Order::ORIGIN_STORE, $manager->driver(Order::ORIGIN_STORE)->channel());
        $this->assertNotContains(Order::ORIGIN_STORE, $manager->channels());
    }
}

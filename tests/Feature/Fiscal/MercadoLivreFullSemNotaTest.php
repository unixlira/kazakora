<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Full do Mercado Livre: a NF-e é do ML, o KazaKora não emite.
 *
 * Caso real 2026-11-04: pedidos #3811, #3812, #3813 e #3818 (Full) saíram
 * com as notas 2-3478 a 2-3481 e o ML recusou todas ("Access denied, you
 * must use the biller of MercadoLibre").
 */
class MercadoLivreFullSemNotaTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(?string $metodoGravado): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => '2000019335047378',
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 82.13,
            'total' => 82.13,
        ]);

        if ($metodoGravado) {
            ChannelShipment::create([
                'order_id' => $order->id,
                'channel' => Order::ORIGIN_MERCADO_LIVRE,
                'external_shipment_id' => '46000000001',
                'shipping_method' => $metodoGravado,
                'status' => ChannelShipment::STATUS_CONFIRMED,
            ]);
        }

        return $order;
    }

    private function mlResponde(?string $logisticType): MercadoLivreClient
    {
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->with('orders/2000019335047378')->andReturn(['shipping' => ['id' => 46000000001]]);
        $client->shouldReceive('get')->with('shipments/46000000001')->andReturn(['logistic_type' => $logisticType]);
        $this->app->instance(MercadoLivreClient::class, $client);

        return $client;
    }

    private function rodarJob(Order $order, InvoiceService $invoices): void
    {
        $orderImport = Mockery::mock(OrderImportService::class);
        $orderImport->shouldReceive('refreshBuyerInfo')->andReturnNull();

        (new GenerateInvoiceJob($order->id))->handle($invoices, new OrderFulfillmentTimeline(), $orderImport);
    }

    private function naoPodeEmitir(): InvoiceService
    {
        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldNotReceive('issue');

        return $invoices;
    }

    public function test_full_gravado_no_envio_nao_emite_e_explica_no_historico(): void
    {
        Queue::fake();
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldNotReceive('get');
        $this->app->instance(MercadoLivreClient::class, $client);

        $order = $this->pedido('fulfillment');
        $this->rodarJob($order, $this->naoPodeEmitir());

        $this->assertSame(0, Invoice::query()->count());
        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $order->id,
            'step' => OrderFulfillmentEvent::STEP_INVOICE_ISSUED,
            'status' => OrderFulfillmentEvent::STATUS_SUCCESS,
        ]);
        $this->assertStringContainsString('Full', (string) OrderFulfillmentEvent::query()->where('order_id', $order->id)->latest('id')->value('message'));
    }

    public function test_envio_ainda_nao_gravado_pergunta_ao_ml_e_full_nao_emite(): void
    {
        Queue::fake();
        $this->mlResponde('fulfillment');

        $this->rodarJob($this->pedido(null), $this->naoPodeEmitir());

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_coleta_e_flex_continuam_emitindo(): void
    {
        Queue::fake();

        foreach (['xd_drop_off', 'self_service'] as $metodo) {
            Order::query()->delete();
            $order = $this->pedido($metodo);

            $invoices = Mockery::mock(InvoiceService::class);
            $invoices->shouldReceive('issue')->once()->andReturn(Invoice::create([
                'order_id' => $order->id, 'status' => Invoice::STATUS_PENDING, 'serie' => 2, 'numero' => 1,
            ]));

            $this->rodarJob($order, $invoices);
        }
    }

    public function test_erro_ao_perguntar_ao_ml_nao_emite_no_escuro(): void
    {
        Queue::fake();
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('get')->andThrow(new MercadoLivreException('fora do ar'));
        $this->app->instance(MercadoLivreClient::class, $client);

        $this->expectException(MercadoLivreException::class);

        $this->rodarJob($this->pedido(null), $this->naoPodeEmitir());
    }

    public function test_retry_stuck_nao_redispara_pedido_do_full(): void
    {
        Queue::fake();
        $full = $this->pedido('fulfillment');

        $this->artisan('nfe:retry-stuck', ['--forcar' => true])->assertSuccessful();

        Queue::assertNotPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $full->id);
    }
}

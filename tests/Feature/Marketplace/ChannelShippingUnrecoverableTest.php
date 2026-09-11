<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Exceptions\ChannelOrderNotFoundException;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\ChannelShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A fila tinha 7.794 falhas em 24h (2026-09-10), quase todas o mesmo
 * punhado de pedidos do TikTok de agosto que o Bling não conhece —
 * reimportados de hora em hora, redisparados, queimando 6 tentativas cada.
 */
class ChannelShippingUnrecoverableTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => 'tiktok_shop',
            'external_order_id' => 'TIKTOK-1',
            'shipping_name' => 'Cliente',
            'shipping_phone' => 'Não informado',
            'shipping_zip' => '00000000',
            'shipping_street' => 'Rua',
            'shipping_number' => 'S/N',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 0,
            'shipping_cost' => 0,
            'total' => 0,
        ]);

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'tiktok_shop',
            'external_shipment_id' => 'TIKTOK-1',
            'shipping_method' => 'TikTok Shop Logistics',
            'status' => ChannelShipment::STATUS_PENDING,
        ]);

        return $order->fresh();
    }

    /** Erro que não muda com o tempo é UMA tentativa, não seis. */
    public function test_a_permanent_channel_error_fails_the_job_without_retrying(): void
    {
        $order = $this->makeOrder();

        $service = Mockery::mock(ChannelShippingService::class);
        $service->shouldReceive('confirm')->once()
            ->andThrow(new ChannelOrderNotFoundException('Pedido TIKTOK-1 não encontrado no Bling ao consultar o envio.'));

        $job = Mockery::mock(ConfirmChannelShippingJob::class.'[fail]', [$order->id]);
        $job->shouldAllowMockingProtectedMethods();
        // fail() marca falha definitiva na hora, sem passar pelo backoff.
        $job->shouldReceive('fail')->once()->with(Mockery::type(ChannelOrderNotFoundException::class));

        $job->handle($service);
    }

    /** E o envio fica marcado, pra varredura nenhuma redisparar amanhã. */
    public function test_the_shipment_is_marked_unrecoverable(): void
    {
        $order = $this->makeOrder();

        $driver = Mockery::mock(\App\Modules\Marketplace\Drivers\MarketplaceChannelDriver::class);
        $driver->shouldReceive('confirmShipping')
            ->andThrow(new ChannelOrderNotFoundException('Pedido TIKTOK-1 não encontrado no Bling ao consultar o envio.'));

        $manager = Mockery::mock(\App\Modules\Marketplace\Drivers\MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(\App\Modules\Marketplace\Drivers\MarketplaceDriverManager::class, $manager);

        try {
            app(ChannelShippingService::class)->confirm($order);
        } catch (ChannelOrderNotFoundException) {
            // esperado
        }

        $shipment = ChannelShipment::where('order_id', $order->id)->first();

        $this->assertNotNull($shipment->unrecoverable_at);
        $this->assertSame(ChannelShipment::STATUS_ERROR, $shipment->status);
    }
}

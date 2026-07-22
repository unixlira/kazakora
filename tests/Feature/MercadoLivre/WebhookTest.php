<?php

namespace Tests\Feature\MercadoLivre;

use App\Jobs\ProcessMercadoLivreWebhook;
use App\Services\MercadoLivre\Services\MessageService;
use App\Services\MercadoLivre\Services\OrderService;
use App\Services\MercadoLivre\Services\ProductService;
use App\Services\MercadoLivre\Services\ShipmentService;
use App\Services\MercadoLivre\Webhooks\WebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_endpoint_acknowledges_immediately_and_queues_processing(): void
    {
        Queue::fake();

        $payload = [
            'topic' => 'orders',
            'resource' => '/orders/123456',
            'user_id' => 123456789,
            'application_id' => 987654321,
        ];

        $response = $this->postJson('/api/mercadolivre/webhook', $payload);

        $response->assertOk();
        $response->assertJson(['status' => 'received']);

        Queue::assertPushed(
            ProcessMercadoLivreWebhook::class,
            fn (ProcessMercadoLivreWebhook $job) => $job->payload['topic'] === 'orders',
        );
    }

    public function test_webhook_handler_dispatches_orders_topic_to_order_service(): void
    {
        $orders = Mockery::mock(OrderService::class);
        $orders->shouldReceive('processWebhook')->once()->with(['topic' => 'orders']);

        $products = Mockery::mock(ProductService::class);
        $products->shouldNotReceive('processWebhook');

        $messages = Mockery::mock(MessageService::class);
        $shipments = Mockery::mock(ShipmentService::class);

        $handler = new WebhookHandler($orders, $products, $messages, $shipments);
        $handler->handle(['topic' => 'orders']);
    }

    public function test_webhook_handler_dispatches_prices_topic_to_product_service_price_update(): void
    {
        $orders = Mockery::mock(OrderService::class);

        $products = Mockery::mock(ProductService::class);
        $products->shouldReceive('processPriceUpdate')->once()->with(['topic' => 'prices']);

        $messages = Mockery::mock(MessageService::class);
        $shipments = Mockery::mock(ShipmentService::class);

        $handler = new WebhookHandler($orders, $products, $messages, $shipments);
        $handler->handle(['topic' => 'prices']);
    }
}

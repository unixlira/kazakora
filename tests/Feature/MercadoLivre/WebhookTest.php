<?php

namespace Tests\Feature\MercadoLivre;

use App\Jobs\ProcessMercadoLivreWebhook;
use App\Modules\Marketplace\Jobs\PokeMercadoLivreLabelChecksJob;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Services\MercadoLivre\Services\MessageService;
use App\Services\MercadoLivre\Services\OrderService;
use App\Services\MercadoLivre\Services\ProductService;
use App\Services\MercadoLivre\Services\ShipmentService;
use App\Services\MercadoLivre\Webhooks\WebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_endpoint_acknowledges_immediately_logs_and_queues_processing(): void
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

        $this->assertDatabaseHas('channel_webhook_logs', [
            'channel' => 'mercado_livre',
            'event_type' => 'orders',
            'status' => ChannelWebhookLog::STATUS_RECEIVED,
        ]);

        $log = ChannelWebhookLog::query()->latest('id')->firstOrFail();

        Queue::assertPushed(
            ProcessMercadoLivreWebhook::class,
            fn (ProcessMercadoLivreWebhook $job) => $job->payload['topic'] === 'orders' && $job->webhookLogId === $log->id,
        );

        // Pedido 2026-08-05: QUALQUER webhook (mesmo um tópico que o
        // WebhookHandler vai ignorar) precisa "cutucar" a reconferência de
        // etiquetas pendentes, não só orders/shipments.
        Queue::assertPushed(PokeMercadoLivreLabelChecksJob::class);
    }

    public function test_webhook_endpoint_pokes_label_checks_even_for_a_topic_the_handler_ignores(): void
    {
        Queue::fake();

        $this->postJson('/api/mercadolivre/webhook', ['topic' => 'payments', 'resource' => '/payments/1'])
            ->assertOk();

        Queue::assertPushed(PokeMercadoLivreLabelChecksJob::class);
    }

    public function test_webhook_handler_dispatches_orders_topic_and_marks_log_processed(): void
    {
        $log = ChannelWebhookLog::create(['channel' => 'mercado_livre', 'event_type' => 'orders', 'status' => ChannelWebhookLog::STATUS_RECEIVED]);

        $orders = Mockery::mock(OrderService::class);
        $orders->shouldReceive('processWebhook')->once()->with(['topic' => 'orders']);

        $products = Mockery::mock(ProductService::class);
        $products->shouldNotReceive('processWebhook');

        $messages = Mockery::mock(MessageService::class);
        $shipments = Mockery::mock(ShipmentService::class);

        $handler = new WebhookHandler($orders, $products, $messages, $shipments);
        $handler->handle(['topic' => 'orders'], $log->id);

        $this->assertSame(ChannelWebhookLog::STATUS_PROCESSED, $log->fresh()->status);
    }

    public function test_webhook_handler_dispatches_prices_topic_to_product_service_price_update(): void
    {
        $log = ChannelWebhookLog::create(['channel' => 'mercado_livre', 'event_type' => 'prices', 'status' => ChannelWebhookLog::STATUS_RECEIVED]);

        $orders = Mockery::mock(OrderService::class);

        $products = Mockery::mock(ProductService::class);
        $products->shouldReceive('processPriceUpdate')->once()->with(['topic' => 'prices']);

        $messages = Mockery::mock(MessageService::class);
        $shipments = Mockery::mock(ShipmentService::class);

        $handler = new WebhookHandler($orders, $products, $messages, $shipments);
        $handler->handle(['topic' => 'prices'], $log->id);

        $this->assertSame(ChannelWebhookLog::STATUS_PROCESSED, $log->fresh()->status);
    }

    public function test_webhook_handler_marks_log_ignored_for_unhandled_topic(): void
    {
        $log = ChannelWebhookLog::create(['channel' => 'mercado_livre', 'event_type' => 'unknown_topic', 'status' => ChannelWebhookLog::STATUS_RECEIVED]);

        $orders = Mockery::mock(OrderService::class);
        $products = Mockery::mock(ProductService::class);
        $messages = Mockery::mock(MessageService::class);
        $shipments = Mockery::mock(ShipmentService::class);

        $handler = new WebhookHandler($orders, $products, $messages, $shipments);
        $handler->handle(['topic' => 'unknown_topic'], $log->id);

        $this->assertSame(ChannelWebhookLog::STATUS_IGNORED, $log->fresh()->status);
    }

    public function test_webhook_handler_marks_log_failed_and_rethrows_when_processing_throws(): void
    {
        $log = ChannelWebhookLog::create(['channel' => 'mercado_livre', 'event_type' => 'orders', 'status' => ChannelWebhookLog::STATUS_RECEIVED]);

        $orders = Mockery::mock(OrderService::class);
        $orders->shouldReceive('processWebhook')->once()->andThrow(new RuntimeException('falha simulada'));

        $products = Mockery::mock(ProductService::class);
        $messages = Mockery::mock(MessageService::class);
        $shipments = Mockery::mock(ShipmentService::class);

        $handler = new WebhookHandler($orders, $products, $messages, $shipments);

        $this->expectException(RuntimeException::class);

        try {
            $handler->handle(['topic' => 'orders'], $log->id);
        } finally {
            $this->assertSame(ChannelWebhookLog::STATUS_FAILED, $log->fresh()->status);
            $this->assertSame('falha simulada', $log->fresh()->error_message);
        }
    }
}

<?php

namespace Tests\Feature\Api;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-print-agent-token'];
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => 'shopee',
            'external_order_id' => 'VENDA-'.uniqid(),
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => 'Não informado',
            'shipping_zip' => '00000000',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => 'S/N',
            'shipping_neighborhood' => 'Não informado',
            'shipping_city' => 'Não informado',
            'shipping_state' => 'SP',
            'subtotal' => 0,
            'shipping_cost' => 0,
            'total' => 0,
        ], $attributes));
    }

    public function test_jobs_list_includes_the_order_sale_id_alongside_the_internal_order_id(): void
    {
        $order = $this->makeOrder(['external_order_id' => '2608091234567']);

        $job = PrintJob::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'tracking_code' => null,
            'label_path' => 'labels/teste.pdf',
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $response = $this->getJson('/api/print-agent/jobs', $this->authHeaders());

        $response->assertOk();
        $response->assertJson([
            'jobs' => [
                [
                    'id' => $job->id,
                    'order_id' => $order->id,
                    'channel' => 'shopee',
                    'tracking_code' => null,
                    'sale_id' => '2608091234567',
                ],
            ],
        ]);
    }

    public function test_jobs_list_reports_a_null_sale_id_for_a_manual_label_without_a_real_order(): void
    {
        PrintJob::create([
            'order_id' => null,
            'channel' => null,
            'label_path' => 'labels/manual.pdf',
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $response = $this->getJson('/api/print-agent/jobs', $this->authHeaders());

        $response->assertOk();
        $response->assertJsonPath('jobs.0.sale_id', null);
    }

    public function test_jobs_list_only_returns_queued_jobs(): void
    {
        $order = $this->makeOrder();

        PrintJob::create([
            'order_id' => $order->id,
            'channel' => 'shopee',
            'label_path' => 'labels/teste.pdf',
            'status' => PrintJob::STATUS_PRINTED,
        ]);

        $response = $this->getJson('/api/print-agent/jobs', $this->authHeaders());

        $response->assertOk();
        $response->assertJsonCount(0, 'jobs');
    }
}

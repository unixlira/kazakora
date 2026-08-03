<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintJobControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createPrintJob(string $status, string $origin = 'shopee'): PrintJob
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => $origin,
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
        ]);

        return PrintJob::create([
            'order_id' => $order->id,
            'label_path' => 'labels/teste.pdf',
            'status' => $status,
        ]);
    }

    public function test_only_admin_can_view_print_job_cards(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($manager)->get('/admin/impressoes')->assertForbidden();
        $this->actingAs($admin)->get('/admin/impressoes')->assertOk();
    }

    public function test_cards_reflect_real_counts_per_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->createPrintJob(PrintJob::STATUS_QUEUED);
        $this->createPrintJob(PrintJob::STATUS_QUEUED);
        $this->createPrintJob(PrintJob::STATUS_FAILED);

        $response = $this->actingAs($admin)->get('/admin/impressoes');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('totalGeral', 3)
            ->where('cards.0.status', PrintJob::STATUS_QUEUED)
            ->where('cards.0.total', 2)
        );
    }

    public function test_list_shows_channel_and_sale_id(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->createPrintJob(PrintJob::STATUS_PRINTED, 'shopee');

        $response = $this->actingAs($admin)->get('/admin/impressoes/lista');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('jobs.data.0.id', $job->id)
            ->where('jobs.data.0.channel', 'Shopee')
            ->where('jobs.data.0.saleId', $job->order->external_order_id)
        );
    }

    public function test_list_filters_by_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->createPrintJob(PrintJob::STATUS_QUEUED);
        $failed = $this->createPrintJob(PrintJob::STATUS_FAILED);

        $response = $this->actingAs($admin)->get('/admin/impressoes/lista?status=failed');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('jobs.data', 1)
            ->where('jobs.data.0.id', $failed->id)
        );
    }
}

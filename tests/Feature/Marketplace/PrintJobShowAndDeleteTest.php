<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintJobShowAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeJobWithOrder(): PrintJob
    {
        $order = Order::create([
            'origin' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_order_id' => 'ML-1',
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);

        return PrintJob::create([
            'order_id' => $order->id,
            'label_path' => 'labels/1/etiqueta-1.pdf',
            'status' => PrintJob::STATUS_QUEUED,
        ]);
    }

    public function test_show_renders_the_job_with_order_data(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->makeJobWithOrder();
        Storage::disk('local')->put($job->label_path, 'conteudo');

        $response = $this->actingAs($admin)->get("/admin/impressoes/{$job->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('job.id', $job->id)
            ->where('job.orderId', $job->order_id)
            ->where('job.saleId', 'ML-1')
            ->where('job.hasLabelFile', true));
    }

    public function test_pdf_streams_the_stored_label(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->makeJobWithOrder();
        Storage::disk('local')->put($job->label_path, '%PDF-1.4 conteudo falso');

        $response = $this->actingAs($admin)->get("/admin/impressoes/{$job->id}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_404s_when_the_file_is_missing(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->makeJobWithOrder();

        $this->actingAs($admin)->get("/admin/impressoes/{$job->id}/pdf")->assertNotFound();
    }

    public function test_destroy_deletes_the_job_and_its_label_file(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $job = $this->makeJobWithOrder();
        Storage::disk('local')->put($job->label_path, 'conteudo');

        $this->actingAs($admin)->delete("/admin/impressoes/{$job->id}")->assertRedirect();

        $this->assertDatabaseMissing('print_jobs', ['id' => $job->id]);
        Storage::disk('local')->assertMissing($job->label_path);
    }
}

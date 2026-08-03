<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintTestControllerTest extends TestCase
{
    use RefreshDatabase;

    private static function minimalPdf(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Resources << >> /Contents 4 0 R >>',
            4 => "<< /Length 9 >>\nstream\nBT ET\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $count = count($objects) + 1;

        $xref = "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($objects as $num => $body) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$num]);
        }

        $pdf .= $xref;
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $pdf;
    }

    private function createOrderWithItems(string $origin): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => $origin,
            'external_order_id' => 'TEST-'.uniqid(),
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

        $order->items()->create([
            'product_name' => 'Produto Teste',
            'product_price' => 10,
            'quantity' => 2,
            'subtotal' => 20,
        ]);

        return $order;
    }

    public function test_only_admin_can_access_print_test_screen(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($manager)->get('/admin/integracoes/teste-impressao')->assertForbidden();
        $this->actingAs($admin)->get('/admin/integracoes/teste-impressao')->assertOk();
    }

    public function test_shopee_channel_converts_zpl_and_creates_print_job(): void
    {
        Storage::fake('local');
        Http::fake([
            'api.labelary.com/*' => Http::response(self::minimalPdf(), 200, ['Content-Type' => 'application/pdf']),
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->createOrderWithItems('shopee');

        $file = UploadedFile::fake()->createWithContent('etiqueta.txt', "^XA^FO20,20^A0N,30,30^FDTeste ZPL^FS^XZ");

        $response = $this->actingAs($admin)->post('/admin/integracoes/teste-impressao', [
            'channel' => 'shopee',
            'order_id' => $order->id,
            'file' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionDoesntHaveErrors();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.labelary.com'));

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'status' => PrintJob::STATUS_QUEUED,
        ]);

        $printJob = PrintJob::query()->where('order_id', $order->id)->firstOrFail();
        Storage::disk('local')->assertExists($printJob->label_path);
    }

    public function test_mercado_livre_channel_sends_pdf_directly_and_creates_print_job(): void
    {
        Storage::fake('local');
        Http::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->createOrderWithItems('mercado_livre');

        $file = UploadedFile::fake()->createWithContent('etiqueta.pdf', self::minimalPdf());

        $response = $this->actingAs($admin)->post('/admin/integracoes/teste-impressao', [
            'channel' => 'mercado_livre',
            'order_id' => $order->id,
            'file' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertNothingSent();

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'status' => PrintJob::STATUS_QUEUED,
        ]);
    }

    public function test_rejects_wrong_file_extension_for_channel(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $order = $this->createOrderWithItems('shopee');

        $file = UploadedFile::fake()->createWithContent('etiqueta.pdf', self::minimalPdf());

        $response = $this->actingAs($admin)->post('/admin/integracoes/teste-impressao', [
            'channel' => 'shopee',
            'order_id' => $order->id,
            'file' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('print_jobs', ['order_id' => $order->id]);
    }
}

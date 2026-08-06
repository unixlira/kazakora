<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MercadoLivreFullPrintControllerTest extends TestCase
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

    private function mockClient(string $zplContents): void
    {
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('getBinary')
            ->once()
            ->with('shipment_labels', Mockery::on(fn ($query) => $query['response_type'] === 'zpl2'))
            ->andReturn(['contents' => $zplContents, 'content_type' => 'application/x-zpl']);

        $this->app->instance(MercadoLivreClient::class, $client);
    }

    public function test_only_admin_can_access_the_form(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($manager)->get('/admin/integracoes/mercado-livre/impressao-full')->assertForbidden();
        $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/impressao-full')->assertOk();
    }

    public function test_store_rejects_empty_codes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/integracoes/mercado-livre/impressao-full', [
            'codes' => '   ',
        ])->assertSessionHasErrors('codes');
    }

    public function test_store_fetches_zpl_converts_to_pdf_and_creates_a_single_print_job(): void
    {
        Storage::fake('local');
        Http::fake([
            'api.labelary.com/*' => Http::response(self::minimalPdf(), 200, ['Content-Type' => 'application/pdf']),
        ]);

        $zpl = "^XA^FO20,20^A0N,30,30^FDEnvio: 1/1^FS^XZ^XA^FO20,20^A0N,30,30^FDEnvio: 1/2^FS^XZ";
        $this->mockClient($zpl);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // 3 formas de separador na mesma entrada (vírgula, espaço, quebra
        // de linha) — todas devem virar 1 shipment_ids único deduplicado.
        $response = $this->actingAs($admin)->post('/admin/integracoes/mercado-livre/impressao-full', [
            'codes' => "73851942\n47699073188, 47700259172 47699073188",
        ]);

        $response->assertRedirect(route('admin.integracoes.mercado-livre.impressao-full'));
        $response->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.labelary.com'));

        $this->assertDatabaseCount('print_jobs', 1);
        $printJob = PrintJob::query()->firstOrFail();
        $this->assertNull($printJob->order_id);
        $this->assertSame(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $printJob->channel);
        $this->assertSame(PrintJob::STATUS_QUEUED, $printJob->status);
        Storage::disk('local')->assertExists($printJob->label_path);
    }

    public function test_store_shows_the_real_ml_error_instead_of_a_generic_failure(): void
    {
        $client = Mockery::mock(MercadoLivreClient::class);
        $client->shouldReceive('getBinary')->once()->andThrow(new \RuntimeException('Erro na API do Mercado Livre (HTTP 404).'));
        $this->app->instance(MercadoLivreClient::class, $client);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->post('/admin/integracoes/mercado-livre/impressao-full', [
            'codes' => '999999999',
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Erro na API do Mercado Livre (HTTP 404).', session('error'));
        $this->assertDatabaseCount('print_jobs', 0);
    }
}

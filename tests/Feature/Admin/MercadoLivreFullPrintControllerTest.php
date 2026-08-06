<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MercadoLivreFullPrintControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ZPL com 2 blocos ^XA...^XZ (2 volumes), mesmo shape do arquivo real
     * baixado do painel do Full que motivou essa tela.
     */
    private const MULTI_LABEL_ZPL = "^XA^FO20,20^A0N,30,30^FDEnvio: 73851942/1^FS^XZ^XA^FO20,20^A0N,30,30^FDEnvio: 73851942/2^FS^XZ";

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

    public function test_only_admin_can_access_the_form(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($manager)->get('/admin/integracoes/mercado-livre/impressao-full')->assertForbidden();
        $this->actingAs($admin)->get('/admin/integracoes/mercado-livre/impressao-full')->assertOk();
    }

    public function test_store_requires_either_file_or_content(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/integracoes/mercado-livre/impressao-full', [])
            ->assertSessionHasErrors(['file', 'content']);
    }

    public function test_store_converts_pasted_zpl_and_creates_a_single_print_job(): void
    {
        Storage::fake('local');
        Http::fake([
            'api.labelary.com/*' => Http::response(self::minimalPdf(), 200, ['Content-Type' => 'application/pdf']),
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->post('/admin/integracoes/mercado-livre/impressao-full', [
            'content' => self::MULTI_LABEL_ZPL,
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

    public function test_store_accepts_an_uploaded_txt_file_instead_of_pasted_content(): void
    {
        Storage::fake('local');
        Http::fake([
            'api.labelary.com/*' => Http::response(self::minimalPdf(), 200, ['Content-Type' => 'application/pdf']),
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $file = UploadedFile::fake()->createWithContent('etiquetas-full.txt', self::MULTI_LABEL_ZPL);

        $this->actingAs($admin)->post('/admin/integracoes/mercado-livre/impressao-full', [
            'file' => $file,
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('print_jobs', 1);
    }

    public function test_store_shows_the_real_conversion_error_instead_of_a_generic_failure(): void
    {
        Http::fake([
            'api.labelary.com/*' => Http::response('não deu', 422),
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->post('/admin/integracoes/mercado-livre/impressao-full', [
            'content' => self::MULTI_LABEL_ZPL,
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('Labelary', session('error'));
        $this->assertDatabaseCount('print_jobs', 0);
    }
}

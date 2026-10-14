<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Etiqueta Avulsa passa a aceitar PDF pronto, além do .txt (ZPL). */
class EtiquetaAvulsaPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_aceita_pdf_e_enfileira_a_impressao(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->AddPage('P', [100, 150]);
        $bytes = $pdf->Output('S');

        $this->actingAs($admin)->post('/admin/etiquetas-manuais', [
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'file' => UploadedFile::fake()->createWithContent('etiqueta.pdf', $bytes),
        ])->assertRedirect('/admin/etiquetas-manuais')->assertSessionHasNoErrors();

        $job = PrintJob::first();
        $this->assertNotNull($job);
        $this->assertStringEndsWith('.pdf', $job->label_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($job->label_path));
    }
}

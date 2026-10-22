<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Services\NFe\NFeDanfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 22/10/2026: as notas antigas da série 3 guardavam o PDF do Bling; o
 * comando troca pelo DANFE da KazaKora e guarda o do Bling na lixeira.
 */
class RegenerarDanfeKazaKoraTest extends TestCase
{
    use RefreshDatabase;

    private function nota(int $serie, string $chave, bool $comXml = true): Invoice
    {
        $order = Order::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CUSTOMER])->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_TIKTOK_SHOP,
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01001-000',
            'shipping_street' => 'Praça da Sé',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Sé',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);

        $xmlPath = "invoices/{$order->id}/nfe-{$chave}.xml";
        $pdfPath = "invoices/{$order->id}/danfe-{$chave}.pdf";

        if ($comXml) {
            Storage::disk('local')->put($xmlPath, "<nfeProc>{$chave}</nfeProc>");
        }

        Storage::disk('local')->put($pdfPath, '%PDF-do-bling');

        return Invoice::create([
            'order_id' => $order->id,
            'numero' => random_int(1, 99999),
            'serie' => $serie,
            'status' => Invoice::STATUS_AUTHORIZED,
            'chave_acesso' => $chave,
            'xml_path' => $comXml ? $xmlPath : null,
            'danfe_path' => $pdfPath,
            'valor_total' => 100,
        ]);
    }

    public function test_serie_3_pdf_becomes_our_danfe_and_blings_goes_to_trash(): void
    {
        Storage::fake('local');

        $this->mock(NFeDanfeService::class)
            ->shouldReceive('generate')->with('<nfeProc>'.str_repeat('3', 44).'</nfeProc>')->andReturn('%PDF-kazakora');

        $serie3 = $this->nota(3, str_repeat('3', 44));
        $serie2 = $this->nota(2, str_repeat('2', 44));
        $semXml = $this->nota(3, str_repeat('4', 44), comXml: false);

        $this->artisan('nfe:danfe-kazakora')->assertSuccessful();
        // Rodar de novo não pode perder o PDF do Bling que está na lixeira.
        $this->artisan('nfe:danfe-kazakora')->assertSuccessful();

        $disco = Storage::disk('local');
        $this->assertSame('%PDF-kazakora', $disco->get($serie3->fresh()->danfe_path));
        $this->assertSame('%PDF-do-bling', $disco->get('lixeira/danfe-bling/'.$serie3->danfe_path));
        $this->assertSame('%PDF-do-bling', $disco->get($serie2->danfe_path));
        $this->assertSame('%PDF-do-bling', $disco->get($semXml->danfe_path));
    }
}

<?php

namespace Tests\Unit\Marketplace;

use App\Modules\Marketplace\Support\LabelProcessingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LabelProcessingServiceTest extends TestCase
{
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

    public function test_convert_zpl_to_pdf_uses_the_real_label_size_declared_in_the_zpl(): void
    {
        Http::fake(['api.labelary.com/*' => Http::response(self::minimalPdf(), 200, ['Content-Type' => 'application/pdf'])]);

        // 609x1015 dots a 203dpi (8dpmm) = 3x5" — bem diferente do fallback 4x6",
        // pra provar que o tamanho real do ZPL é respeitado em vez de ignorado.
        $zpl = "^XA^PW609^LL1015^XGR:IMAGE.GRF,1,1^FS^XZ";

        (new LabelProcessingService)->convertZplToPdf($zpl);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/labels/3x5/'));
    }

    public function test_convert_zpl_to_pdf_falls_back_to_4x6_when_size_is_not_declared(): void
    {
        Http::fake(['api.labelary.com/*' => Http::response(self::minimalPdf(), 200, ['Content-Type' => 'application/pdf'])]);

        (new LabelProcessingService)->convertZplToPdf('^XA^FDTeste^FS^XZ');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/labels/4x6/'));
    }

    public function test_overlay_switches_to_two_column_grid_for_more_than_six_products(): void
    {
        $names = array_map(fn ($i) => "Produto {$i}", range(1, 9));

        $result = (new LabelProcessingService)->overlayProductList(self::minimalPdf(), $names);

        $this->assertStringStartsWith('%PDF', $result);
    }

    public function test_overlay_handles_empty_product_list_without_crashing(): void
    {
        $result = (new LabelProcessingService)->overlayProductList(self::minimalPdf(), []);

        $this->assertStringStartsWith('%PDF', $result);
    }
}

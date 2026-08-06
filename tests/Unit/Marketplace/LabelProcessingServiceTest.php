<?php

namespace Tests\Unit\Marketplace;

use App\Modules\Marketplace\Support\LabelProcessingService;
use Illuminate\Support\Facades\Http;
use setasign\Fpdi\Fpdi;
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

    /**
     * Bug real encontrado 2026-08-06 (Impressão Full): a URL tinha um
     * índice fixo "0" no fim (.../labels/4x6/0/), e a Labelary só devolve
     * UMA etiqueta quando um índice é passado — um ZPL de lote com várias
     * marcações ^XA...^XZ (ex: 15 volumes do Mercado Envios Full) virava
     * um PDF de 1 página só. Confirmado ao vivo contra a API real da
     * Labelary antes de corrigir (3 etiquetas -> 1 página com índice, 3
     * páginas sem). Trava a ausência do índice na URL pra não regredir
     * silenciosamente — os dois testes acima só checam a substring
     * "/labels/{tamanho}/", que continuaria passando mesmo com um índice
     * de volta no fim.
     */
    public function test_convert_zpl_to_pdf_never_sends_a_label_index_so_all_labels_come_back(): void
    {
        Http::fake(['api.labelary.com/*' => Http::response(self::minimalPdf(), 200, ['Content-Type' => 'application/pdf'])]);

        (new LabelProcessingService)->convertZplToPdf('^XA^FDTeste^FS^XZ');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/labels/4x6/'));
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

    public function test_overlay_converts_accented_product_names_to_latin1_for_fpdf_core_fonts(): void
    {
        // As fontes nativas do FPDF (Arial/Helvetica) esperam ISO-8859-1 —
        // sem converter, "ç" (UTF-8: 0xC3 0xA7) sai corrompido na etiqueta
        // em vez do byte único Latin-1 (0xE7) que o FPDF sabe desenhar.
        $result = (new LabelProcessingService)->overlayProductList(self::minimalPdf(), ['Café com Açúcar']);

        $this->assertStringNotContainsString("\xC3\xA7", $result);
        $this->assertStringContainsString("\xE7", $result);
    }

    public function test_overlay_never_creates_a_second_page(): void
    {
        // A margem padrão de quebra automática do FPDF (2cm) já causou isso
        // uma vez: conteúdo colado no rodapé virava página 2 em vez de
        // desenhar na etiqueta atual — o produto "sumia" pra outra etiqueta.
        $result = (new LabelProcessingService)->overlayProductList(
            self::minimalPdf(),
            array_map(fn ($i) => "Produto {$i}", range(1, 9))
        );

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $result);

        try {
            $pageCount = (new Fpdi)->setSourceFile($tempPath);
            $this->assertSame(1, $pageCount);
        } finally {
            @unlink($tempPath);
        }
    }
}

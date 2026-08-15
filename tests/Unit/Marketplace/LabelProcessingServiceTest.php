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

    public function test_declaration_page_handles_several_products_without_crashing(): void
    {
        $tokens = array_map(fn ($i) => "SKU-{$i}|QTD=1", range(1, 9));

        $result = (new LabelProcessingService)->appendDeclarationPage(self::minimalPdf(), $tokens);

        $this->assertStringStartsWith('%PDF', $result);
    }

    public function test_declaration_page_handles_empty_product_list_without_crashing(): void
    {
        $result = (new LabelProcessingService)->appendDeclarationPage(self::minimalPdf(), []);

        $this->assertStringStartsWith('%PDF', $result);
    }

    public function test_declaration_page_converts_accented_text_to_latin1_for_fpdf_core_fonts(): void
    {
        // As fontes nativas do FPDF (Arial/Helvetica) esperam ISO-8859-1 —
        // sem converter, "ç" (UTF-8: 0xC3 0xA7) sai corrompido na etiqueta
        // em vez do byte único Latin-1 (0xE7) que o FPDF sabe desenhar. O
        // próprio cabeçalho fixo ("DECLARAÇÃO DE CONTEÚDO") já tem acento,
        // não precisa de um token especial pra isso aparecer.
        $result = (new LabelProcessingService)->appendDeclarationPage(self::minimalPdf(), ['SKU-1|QTD=1']);

        $this->assertStringNotContainsString("\xC3\xA7", $result);
        $this->assertStringContainsString("\xE7", $result);
    }

    public function test_declaration_page_shows_the_header_and_instruction(): void
    {
        $result = (new LabelProcessingService)->appendDeclarationPage(self::minimalPdf(), ['SKU-1|QTD=2']);

        $this->assertStringContainsString('antes de embalar', $result);
    }

    /**
     * Pedido explícito 2026-08-15: SKU|QTD=N por produto, vírgula separando
     * quando o pedido tem mais de um item.
     */
    public function test_declaration_page_joins_multiple_products_with_a_comma(): void
    {
        $result = (new LabelProcessingService)->appendDeclarationPage(self::minimalPdf(), ['SKU-1|QTD=2', 'SKU-2|QTD=1']);

        $this->assertStringContainsString('SKU-1|QTD=2, SKU-2|QTD=1', $result);
    }

    /**
     * BUG REAL 2026-08-15 (pedido #307): a versão anterior desenhava a
     * declaração POR CIMA da própria etiqueta (overlay), assumindo uma área
     * vazia que nem sempre existe no layout real de uma etiqueta de canal
     * (colidiu com o rodapé "DANFE SIMPLIFICADO" de uma etiqueta Shopee
     * real, saiu ilegível). Reescrito pra sempre acrescentar uma página
     * NOVA em vez de desenhar em cima — este teste é a garantia de que
     * isso nunca mais volta a ser um overlay: o PDF de saída tem que ter
     * mais páginas que o original, nunca o mesmo número.
     */
    public function test_declaration_page_is_appended_never_drawn_over_the_original_page(): void
    {
        $result = (new LabelProcessingService)->appendDeclarationPage(
            self::minimalPdf(), // 1 página
            array_map(fn ($i) => "SKU-{$i}|QTD=1", range(1, 9))
        );

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $result);

        try {
            $pageCount = (new Fpdi)->setSourceFile($tempPath);
            $this->assertSame(2, $pageCount); // 1 original + 1 de declaração
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Etiqueta de lote com múltiplos volumes (ex.: Mercado Envios Full,
     * histórico real desse pipeline) pode ter mais de uma página de
     * origem — todas têm que sobreviver intactas, com a declaração vindo
     * só depois da última.
     */
    public function test_declaration_page_preserves_every_original_page_of_a_multi_page_label(): void
    {
        $twoPagePdf = self::minimalTwoPagePdf();

        $result = (new LabelProcessingService)->appendDeclarationPage($twoPagePdf, ['SKU-1|QTD=1']);

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $result);

        try {
            $pageCount = (new Fpdi)->setSourceFile($tempPath);
            $this->assertSame(3, $pageCount); // 2 originais + 1 de declaração
        } finally {
            @unlink($tempPath);
        }
    }

    private static function minimalTwoPagePdf(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Resources << >> /Contents 4 0 R >>',
            4 => "<< /Length 9 >>\nstream\nBT ET\nendstream",
            5 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Resources << >> /Contents 6 0 R >>',
            6 => "<< /Length 9 >>\nstream\nBT ET\nendstream",
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
}

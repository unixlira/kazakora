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

    public function test_declaration_footer_handles_several_products_without_crashing(): void
    {
        $tokens = array_map(fn ($i) => "SKU-{$i} | QTD: 01", range(1, 9));

        $result = (new LabelProcessingService)->overlayDeclarationFooter(self::minimalPdf(), $tokens);

        $this->assertStringStartsWith('%PDF', $result);
    }

    public function test_declaration_footer_handles_empty_product_list_without_crashing(): void
    {
        $result = (new LabelProcessingService)->overlayDeclarationFooter(self::minimalPdf(), []);

        $this->assertStringStartsWith('%PDF', $result);
    }

    public function test_declaration_footer_converts_accented_text_to_latin1_for_fpdf_core_fonts(): void
    {
        // As fontes nativas do FPDF (Arial/Helvetica) esperam ISO-8859-1 —
        // sem converter, "ç" (UTF-8: 0xC3 0xA7) sai corrompido na etiqueta
        // em vez do byte único Latin-1 (0xE7) que o FPDF sabe desenhar.
        $result = (new LabelProcessingService)->overlayDeclarationFooter(self::minimalPdf(), ['SKU-ÁÇÃO | QTD: 01']);

        $this->assertStringNotContainsString("\xC3\xA7", $result);
        $this->assertStringContainsString("\xE7", $result);
    }

    public function test_declaration_footer_shows_the_sku_and_quantity_token(): void
    {
        $result = (new LabelProcessingService)->overlayDeclarationFooter(self::minimalPdf(), ['SKU-1 | QTD: 02']);

        $this->assertStringContainsString('SKU-1 | QTD: 02', $result);
    }

    /**
     * Pedido explícito 2026-08-15: SKU | QTD: NN por produto, vírgula
     * separando quando o pedido tem mais de um item.
     */
    public function test_declaration_footer_joins_multiple_products_with_a_comma(): void
    {
        $result = (new LabelProcessingService)->overlayDeclarationFooter(self::minimalPdf(), ['SKU-1 | QTD: 02', 'SKU-2 | QTD: 01']);

        $this->assertStringContainsString('SKU-1 | QTD: 02, SKU-2 | QTD: 01', $result);
    }

    /**
     * BUG REAL 2026-08-15 (pedido #307): overlay colidiu com o rodapé
     * "DANFE SIMPLIFICADO" real de uma etiqueta Shopee -> virou página
     * extra -> pedido explícito do usuário voltou atrás (página extra
     * desperdiça uma etiqueta térmica inteira por pedido). De volta a
     * overlay na MESMA página — este teste é a garantia de que a etiqueta
     * continua sendo 1 página só, nunca 2 (o desperdício de papel que
     * motivou a reversão).
     */
    public function test_declaration_footer_never_creates_a_second_page(): void
    {
        $result = (new LabelProcessingService)->overlayDeclarationFooter(
            self::minimalPdf(), // 1 página
            array_map(fn ($i) => "SKU-{$i} | QTD: 01", range(1, 9))
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

    /**
     * Etiqueta de lote com múltiplos volumes (ex.: Mercado Envios Full,
     * histórico real desse pipeline) pode ter mais de uma página de
     * origem — todas têm que sobreviver intactas (só a página escolhida por
     * $targetPage ganha a faixa, ver testes abaixo), sem nenhuma página
     * extra.
     */
    public function test_declaration_footer_preserves_every_original_page_of_a_multi_page_label(): void
    {
        $twoPagePdf = self::minimalTwoPagePdf();

        $result = (new LabelProcessingService)->overlayDeclarationFooter($twoPagePdf, ['SKU-1 | QTD: 01']);

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $result);

        try {
            $pageCount = (new Fpdi)->setSourceFile($tempPath);
            $this->assertSame(2, $pageCount); // as 2 originais, sem página extra
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Pedido explícito 2026-08-17: 3º parâmetro opcional de
     * overlayDeclarationFooter() — 2ª linha exclusiva de venda com entrega
     * programada, abaixo da linha de SKU, sem quebrar o comportamento
     * default (null) já coberto pelos testes acima.
     */
    public function test_declaration_footer_shows_the_scheduled_line_below_the_sku_line(): void
    {
        $result = (new LabelProcessingService)->overlayDeclarationFooter(
            self::minimalPdf(),
            ['SKU-1 | QTD: 01'],
            'Pedido agendado dia 20/08/2026 | Pedido no 305',
        );

        $this->assertStringContainsString('SKU-1 | QTD: 01', $result);
        $this->assertStringContainsString('Pedido agendado dia 20/08/2026', $result);
        $this->assertStringContainsString('305', $result);
    }

    /**
     * Mesma garantia dos testes de página única já existentes — a linha
     * extra é reservada dentro da MESMA faixa de rodapé, nunca cria página
     * nova.
     */
    public function test_declaration_footer_with_scheduled_line_never_creates_a_second_page(): void
    {
        $result = (new LabelProcessingService)->overlayDeclarationFooter(
            self::minimalPdf(),
            ['SKU-1 | QTD: 01'],
            'Pedido agendado dia 20/08/2026 | Pedido no 305',
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

    /**
     * BUG REAL 2026-08-21 (etiqueta real do Mercado Livre, achado numa
     * venda de verdade): a etiqueta do ML sempre vem em 2 páginas — a
     * etiqueta de envio (sem espaço livre nenhum na borda inferior,
     * endereço/QR code chegam quase no fim) e uma DANFE simplificada numa
     * 2ª página, com uma seção "DADOS ADICIONAIS" vazia. Desenhar a faixa
     * na 1ª página (comportamento antigo, igual pra todo canal) atropelava
     * o endereço, ficava ilegível. $targetPage='last' resolve isso —
     * continua sem criar página extra, só muda ONDE a faixa é desenhada.
     */
    public function test_declaration_footer_targets_the_last_page_when_requested(): void
    {
        $result = (new LabelProcessingService)->overlayDeclarationFooter(
            self::minimalTwoPagePdf(),
            ['SKU-1 | QTD: 01'],
            targetPage: 'last',
        );

        $this->assertStringContainsString('SKU-1 | QTD: 01', $result);

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $result);

        try {
            $pageCount = (new Fpdi)->setSourceFile($tempPath);
            $this->assertSame(2, $pageCount, 'nunca cria página extra, mesmo mirando a última');
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Etiqueta de canal sem DANFE simplificada de 2ª página (ex.: teste
     * manual, ou um formato futuro de 1 página só) com $targetPage='last'
     * não pode quebrar tentando desenhar numa página inexistente — cai pra
     * página 1 (a única que existe) automaticamente.
     */
    public function test_declaration_footer_falls_back_to_the_only_page_when_target_is_last_but_pdf_has_one_page(): void
    {
        $result = (new LabelProcessingService)->overlayDeclarationFooter(
            self::minimalPdf(),
            ['SKU-1 | QTD: 01'],
            targetPage: 'last',
        );

        $this->assertStringContainsString('SKU-1 | QTD: 01', $result);

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $result);

        try {
            $pageCount = (new Fpdi)->setSourceFile($tempPath);
            $this->assertSame(1, $pageCount);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Pedido explícito 2026-08-21: substitui a sobreposição por rodapé no
     * fluxo automático (ver LabelFetchService) — etiqueta original
     * encolhida na metade esquerda, declaração na direita, sempre 1
     * etiqueta física só mesmo que a origem tenha mais páginas (a DANFE
     * simplificada de uma etiqueta real do Mercado Livre, por exemplo).
     */
    public function test_compose_side_by_side_keeps_a_single_physical_page_even_with_a_multi_page_source(): void
    {
        $result = (new LabelProcessingService)->composeSideBySideLabel(
            self::minimalTwoPagePdf(),
            ['SKU-1 | QTD: 01'],
        );

        $this->assertStringContainsString('DECLARA', $result); // "DECLARAÇÃO" sai em Latin-1
        $this->assertStringContainsString('SKU-1 | QTD: 01', $result);

        $tempPath = tempnam(sys_get_temp_dir(), 'label_result_').'.pdf';
        file_put_contents($tempPath, $result);

        try {
            $pageCount = (new Fpdi)->setSourceFile($tempPath);
            $this->assertSame(1, $pageCount);
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_compose_side_by_side_handles_empty_product_list_without_crashing(): void
    {
        $result = (new LabelProcessingService)->composeSideBySideLabel(self::minimalPdf(), []);

        $this->assertStringStartsWith('%PDF', $result);
        $this->assertStringContainsString('(sem produtos)', $result);
    }

    /**
     * BUG REAL 2026-08-21 (visto na 1ª etiqueta de teste gerada de
     * verdade): um SKU sem espaço nenhum (padrão real do
     * SkuGeneratorService, ex: "ORG-DIS-LCK-ABS-INOX-0001") mais largo que
     * o painel direito forçava o FPDF a quebrar linha NO MEIO de uma
     * palavra ("ABS-IN" / "OX-0001"), ilegível — MultiCell só sabe quebrar
     * em espaço. Insere um espaço depois de cada hífen só pra exibição, dá
     * ponto de quebra natural sem cortar letra nenhuma ao meio.
     */
    public function test_compose_side_by_side_breaks_a_long_hyphenated_sku_at_the_hyphen_not_mid_word(): void
    {
        $result = (new LabelProcessingService)->composeSideBySideLabel(
            self::minimalPdf(),
            ['ORG-DIS-LCK-ABS-INOX-0001 | QTD: 01'],
        );

        $this->assertStringNotContainsString('ABS-IN', $result, 'não pode quebrar no meio de "INOX"');
        $this->assertStringContainsString('ORG-', $result);
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

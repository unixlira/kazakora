<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Processamento de etiqueta — converte ZPL pra PDF quando o canal exige
 * (Shopee) e acrescenta a declaração de conteúdo (SKU + quantidade) como
 * página extra no fim do PDF antes de mandar pro PrintJob. Usado tanto pelo
 * fluxo automático (LabelFetchService::attempt(), Shopee/TikTok) quanto pela
 * tela de teste de impressão (Admin/Integracoes/TesteImpressao).
 */
class LabelProcessingService
{
    /** Dots por mm assumido pro ZPL da Shopee — impressoras térmicas de etiqueta de marketplace usam quase sempre 203dpi. */
    private const DENSITY_DPMM = 8;


    /**
     * Zebra (ZPL) não tem conversor nativo em PHP — delega pra API pública
     * da Labelary (gratuita, sem autenticação).
     *
     * O ZPL da Shopee traz a etiqueta inteira renderizada como bitmap
     * (~DG/^XG), então o tamanho real da etiqueta vem dos comandos
     * ^PW (largura em dots) e ^LL (comprimento em dots) do próprio ZPL —
     * pedir ao Labelary um tamanho fixo diferente do declarado distorce a
     * imagem e foi a causa raiz do texto saindo grande demais e cobrindo
     * o código de barras. Se o ZPL não declarar ^PW/^LL, cai no fallback
     * 4x6" (formato mais comum de etiqueta de envio).
     *
     * Sem o segmento de índice (0-based) no fim da URL de propósito — bug
     * real encontrado 2026-08-06 (Impressão Full): com esse índice fixo
     * em "0", a Labelary sempre devolvia SÓ a primeira etiqueta do ZPL,
     * mesmo com várias marcações ^XA...^XZ na entrada (ex: um lote do
     * Mercado Envios Full com 15 volumes virava um PDF de 1 página só).
     * A própria documentação da Labelary confirma: pra resposta em PDF,
     * omitir o índice devolve TODAS as etiquetas, uma por página — é
     * estritamente melhor que o índice fixo pros outros chamadores desse
     * método também (ZPL de 1 etiqueta só continua saindo com 1 página).
     */
    public function convertZplToPdf(string $zpl): string
    {
        [$width, $height] = $this->extractLabelSizeInInches($zpl);

        $response = Http::withHeaders(['Accept' => 'application/pdf'])
            ->withBody($zpl, 'application/x-www-form-urlencoded')
            ->post("http://api.labelary.com/v1/printers/".self::DENSITY_DPMM."dpmm/labels/{$width}x{$height}/");

        if ($response->failed()) {
            throw new RuntimeException(
                'Labelary não conseguiu converter o ZPL pra PDF: '.$response->status().' — '.$response->body()
            );
        }

        return $response->body();
    }

    /**
     * @return array{0: float, 1: float} largura e altura em polegadas
     */
    private function extractLabelSizeInInches(string $zpl): array
    {
        if (! preg_match('/\^PW(\d+)/', $zpl, $widthMatch) || ! preg_match('/\^LL(\d+)/', $zpl, $heightMatch)) {
            return [4.0, 6.0];
        }

        $dotsPerInch = self::DENSITY_DPMM * 25.4;

        return [
            round((int) $widthMatch[1] / $dotsPerInch, 2),
            round((int) $heightMatch[1] / $dotsPerInch, 2),
        ];
    }

    /**
     * Acrescenta a declaração de conteúdo como uma PÁGINA NOVA no fim do
     * PDF (mesmo tamanho da etiqueta térmica, 10x15cm/4x6"), nunca
     * desenhando por cima da etiqueta original.
     *
     * BUG REAL 2026-08-15 (pedido #307, achado direto na etiqueta física
     * impressa): a versão anterior desenhava a declaração como uma faixa
     * sobreposta na borda inferior da MESMA página, assumindo que aquela
     * área ficava sempre vazia — só que o layout real desse pedido Shopee
     * já tem um rodapé "DANFE SIMPLIFICADO" com o próprio código de barras
     * bem colado na borda inferior, e a faixa saiu direto em cima dele
     * (ilegível), com o texto ainda vazando pra fora da página pela
     * direita. Não dá pra confiar em heurística de "área vazia" numa
     * etiqueta real cujo layout varia por conta/transportadora — a única
     * garantia de nunca sobrepor nada é nunca desenhar na mesma página.
     * Uma página extra no fim custa uma etiqueta a mais de papel térmico,
     * mas nunca estraga a etiqueta de envio em si.
     *
     * Existe pra reduzir erro de quantidade errada enviada (causa real de
     * vários pedidos errados na implantação inicial) — quem embala confere
     * a etiqueta impressa contra o pedido físico antes de fechar a caixa,
     * em vez de confiar de cabeça na tela do sistema.
     *
     * @param  array<int, string>  $declarationTokens  já formatados pelo chamador (ex.: "SKU123|QTD=2"), um por produto
     */
    public function appendDeclarationPage(string $pdfBytes, array $declarationTokens): string
    {
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'label_source_').'.pdf';
        file_put_contents($tempPdfPath, $pdfBytes);

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($tempPdfPath);

            // Reimporta TODAS as páginas originais sem tocar em nada nelas
            // (etiqueta de lote com múltiplos volumes pode vir com mais de
            // uma página, ver histórico do Mercado Envios Full) — cada uma
            // vira uma página idêntica no PDF de saída.
            $lastSize = null;

            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($templateId);
                $lastSize = $size;

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);

                // Desloca só a PRIMEIRA página ~10px pra direita — ela vinha
                // colada na borda esquerda cortando o "Destinatário"
                // impresso ali, enquanto sobrava espaço em branco do lado
                // direito (achado real, etiquetas anteriores). Área da
                // página não muda, só a posição do conteúdo original nela.
                $leftMarginMm = $i === 1 ? 10 * (25.4 / 96) : 0; // 10px a 96dpi ≈ 2.65mm
                $pdf->useTemplate($templateId, $leftMarginMm, 0);
            }

            // Página nova, em branco, do mesmo tamanho da etiqueta — só a
            // declaração vive aqui, nunca em cima de nada da etiqueta real.
            $pdf->AddPage($lastSize['orientation'], [$lastSize['width'], $lastSize['height']]);
            $pdf->SetAutoPageBreak(true, 6); // se a lista for enorme, continua em mais uma página em vez de vazar/cortar texto

            $marginSide = 6; // mm
            $contentWidth = $lastSize['width'] - (2 * $marginSide);

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($marginSide, 6);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->MultiCell($contentWidth, 5, $this->toLatin1('DECLARAÇÃO DE CONTEÚDO'), 0, 'C');

            $pdf->SetX($marginSide);
            $pdf->SetFont('Arial', '', 7);
            $pdf->MultiCell($contentWidth, 4, $this->toLatin1('Conferir SKU e quantidade antes de embalar'), 0, 'C');

            $pdf->Ln(2);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.3);
            $pdf->Line($marginSide, $pdf->GetY(), $lastSize['width'] - $marginSide, $pdf->GetY());
            $pdf->Ln(3);

            // "SKU123|QTD=2, SKU456|QTD=1" — pedido explícito 2026-08-15
            // (SKU em vez do nome do produto: mais curto, sem risco de
            // vazar da página, e é o que quem confere o pedido físico
            // realmente usa pra bater com a etiqueta do produto no
            // estoque). MultiCell quebra linha sozinho se não couber numa
            // só — nunca corta/vaza como o Cell de largura fixa da versão
            // anterior cortava.
            $line = $declarationTokens !== [] ? implode(', ', $declarationTokens) : '(sem produtos)';

            $pdf->SetX($marginSide);
            $pdf->SetFont('Arial', 'B', 13);
            $pdf->MultiCell($contentWidth, 6, $this->toLatin1($line), 0, 'C');

            return $pdf->Output('S');
        } finally {
            @unlink($tempPdfPath);
        }
    }

    /**
     * As fontes nativas do FPDF (Arial/Helvetica) esperam ISO-8859-1, não
     * UTF-8 — sem essa conversão, acento em nome de produto ("ã", "ç" etc,
     * comuns em português) sai como caractere corrompido na etiqueta.
     */
    private function toLatin1(string $text): string
    {
        return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }
}

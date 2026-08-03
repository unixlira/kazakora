<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Processamento de etiqueta pra tela de teste de impressão
 * (Admin/Integracoes/TesteImpressao) — converte ZPL pra PDF quando o canal
 * exige (Shopee) e sobrepõe a lista de produtos do pedido em negrito antes
 * de mandar pro PrintJob. Serviço isolado de propósito: usado só pela tela
 * de teste hoje, mas é candidato natural a reaproveitar quando o fluxo
 * automático (PollChannelShippingLabels) também precisar dessa sobreposição.
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
     */
    public function convertZplToPdf(string $zpl): string
    {
        [$width, $height] = $this->extractLabelSizeInInches($zpl);

        $response = Http::withHeaders(['Accept' => 'application/pdf'])
            ->withBody($zpl, 'application/x-www-form-urlencoded')
            ->post("http://api.labelary.com/v1/printers/".self::DENSITY_DPMM."dpmm/labels/{$width}x{$height}/0/");

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
     * Adiciona a lista de produtos no rodapé da etiqueta, abaixo de uma
     * linha separadora, sem tocar em mais nada da etiqueta original (FPDI
     * importa a página como template e só desenha por cima — nenhum
     * retângulo de fundo é pintado, então nada da etiqueta original é
     * apagado, mesmo que a faixa calculada não fique exatamente onde
     * deveria).
     *
     * Fica sempre colada na borda inferior, com fonte pequena (mesma
     * ordem de grandeza do DANFE simplificado, não do texto principal da
     * etiqueta) — se a lista tiver mais de 6 produtos, reduz a fonte e
     * divide em duas colunas (grid) pra não crescer verticalmente e
     * invadir a área do código de barras/QR/endereço, que ficam sempre
     * acima dessa faixa.
     *
     * @param  array<int, string>  $productNames
     */
    public function overlayProductList(string $pdfBytes, array $productNames): string
    {
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'label_source_').'.pdf';
        file_put_contents($tempPdfPath, $pdfBytes);

        try {
            $pdf = new Fpdi();
            $pdf->setSourceFile($tempPdfPath);
            $templateId = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($templateId);

            // Sem isso, a margem padrão de quebra automática do FPDF (2cm)
            // manda o conteúdo colado no rodapé pra uma SEGUNDA página em
            // vez de desenhar na etiqueta atual — foi por isso que o nome
            // do produto saiu numa "etiqueta" separada.
            $pdf->SetAutoPageBreak(false);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            $names = $productNames !== [] ? $productNames : ['(sem produtos)'];
            $columns = count($names) > 6 ? 2 : 1;
            $fontSize = $columns > 1 ? 6 : 7;
            $lineHeight = $fontSize * 0.42; // mm — compacto, do tamanho do texto miúdo do DANFE simplificado

            $marginSide = 6; // mm — mais folga que antes: 3mm cortava o "Destinatário" impresso perto da borda esquerda
            $marginBottom = 2; // mm
            $lineGap = 1.5; // mm entre a linha separadora e o texto

            $itemsPerColumn = (int) ceil(count($names) / $columns);
            $bandHeight = ($itemsPerColumn * $lineHeight) + $lineGap + 1;
            $lineY = $size['height'] - $marginBottom - $bandHeight;
            $textTop = $lineY + $lineGap;

            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.3);
            $pdf->Line($marginSide, $lineY, $size['width'] - $marginSide, $lineY);

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', 'B', $fontSize);

            $columnWidth = ($size['width'] - (2 * $marginSide)) / $columns;

            foreach ($names as $index => $name) {
                $column = (int) floor($index / $itemsPerColumn);
                $row = $index % $itemsPerColumn;

                $pdf->SetXY($marginSide + ($column * $columnWidth), $textTop + ($row * $lineHeight));
                $pdf->Cell($columnWidth - 1, $lineHeight, $name, 0, 0, 'C');
            }

            return $pdf->Output('S');
        } finally {
            @unlink($tempPdfPath);
        }
    }
}

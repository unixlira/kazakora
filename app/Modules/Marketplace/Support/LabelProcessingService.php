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
    /**
     * Zebra (ZPL) não tem conversor nativo em PHP — delega pra API pública
     * da Labelary (gratuita, sem autenticação, confirmada em uso real —
     * o próprio usuário já tinha essa ferramenta aberta no navegador).
     * Assume densidade 8dpmm (203dpi) e etiqueta 4x6" — os valores mais
     * comuns pra etiqueta de envio de marketplace; se o ZPL de origem usar
     * outro tamanho, isso precisa virar configurável (não dá pra saber sem
     * testar contra uma etiqueta real da Shopee).
     */
    public function convertZplToPdf(string $zpl): string
    {
        $response = Http::withHeaders(['Accept' => 'application/pdf'])
            ->withBody($zpl, 'application/x-www-form-urlencoded')
            ->post('http://api.labelary.com/v1/printers/8dpmm/labels/4x6/0/');

        if ($response->failed()) {
            throw new RuntimeException(
                'Labelary não conseguiu converter o ZPL pra PDF: '.$response->status().' — '.$response->body()
            );
        }

        return $response->body();
    }

    /**
     * Sobrescreve uma área da primeira página do PDF com a lista de
     * produtos em negrito grosso — o resto da etiqueta original permanece
     * intacto (FPDI importa a página como template e desenha por cima, só
     * a região do retângulo branco é realmente coberta).
     *
     * Área escolhida (canto superior, faixa horizontal cheia) é um
     * primeiro palpite razoável, não validado contra etiqueta real de
     * nenhum canal ainda — é exatamente o que esta tela de teste existe
     * pra descobrir. Ajustar $marginTop/$bandHeight depois de ver o
     * resultado impresso de verdade.
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

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            $marginTop = 4; // mm
            $bandHeight = min(40, $size['height'] * 0.35); // mm — nunca mais que 35% da etiqueta

            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect(2, $marginTop, $size['width'] - 4, $bandHeight, 'F');

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', 'B', 13);
            $pdf->SetXY(3, $marginTop + 1);

            $text = implode("\n", $productNames !== [] ? $productNames : ['(sem produtos)']);
            $pdf->MultiCell($size['width'] - 6, 5.5, $text, 0, 'L');

            return $pdf->Output('S');
        } finally {
            @unlink($tempPdfPath);
        }
    }
}

<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Processamento de etiqueta — converte ZPL pra PDF quando o canal exige
 * (Shopee) e sobrepõe a declaração de conteúdo (SKU + quantidade) numa
 * faixa fina no rodapé da própria etiqueta antes de mandar pro PrintJob —
 * uma etiqueta por pedido, nunca duas (ver overlayDeclarationFooter() pro
 * histórico de por que não é mais página extra). Usado tanto pelo fluxo
 * automático (LabelFetchService::attempt(), Shopee/TikTok) quanto pela tela
 * de teste de impressão (Admin/Integracoes/TesteImpressao).
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
     * Sobrepõe a declaração de conteúdo (SKU|QTD por produto) numa faixa
     * fina colada na borda inferior da PRÓPRIA etiqueta — de volta pro
     * rodapé, mesmo lugar do trecho comentado original (ver histórico:
     * desativado em 8a5032d, reativado com overlay em 7a9208b, trocado pra
     * página extra em ad19796 depois do pedido #307 sair com a faixa em
     * cima do rodapé "DANFE SIMPLIFICADO" da etiqueta). Voltou a ser
     * overlay por pedido explícito 2026-08-15 — a página extra desperdiça
     * uma etiqueta térmica inteira por pedido (custo real de papel), e o
     * texto agora é bem mais curto (só SKU, sem nome do produto), o que
     * reduz — mas não elimina — o risco de colidir com algo que já exista
     * na borda inferior de uma etiqueta real.
     *
     * IMPORTANTE (limite conhecido, não escondido): em etiquetas Shopee que
     * já trazem o rodapé "DANFE SIMPLIFICADO" (referência à NF-e, com o
     * próprio código de barras pequeno) colado na borda inferior — como a
     * do pedido #307 — esta faixa ainda pode ficar próxima ou por cima
     * desse rodapé auxiliar. NÃO fica em cima do código de barras PRINCIPAL
     * de rastreio (esse fica bem mais acima na etiqueta, sempre livre) —
     * só o código de barras pequeno do DANFE simplificado, que a
     * transportadora não escaneia no fluxo normal, é quem corre esse risco.
     *
     * Existe pra reduzir erro de quantidade errada enviada (causa real de
     * vários pedidos errados na implantação inicial) — quem embala confere
     * a etiqueta impressa contra o pedido físico antes de fechar a caixa,
     * em vez de confiar de cabeça na tela do sistema.
     *
     * @param  array<int, string>  $declarationTokens  já formatados pelo chamador (ex.: "SKU123 | QTD: 02"), um por produto
     */
    public function overlayDeclarationFooter(string $pdfBytes, array $declarationTokens): string
    {
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'label_source_').'.pdf';
        file_put_contents($tempPdfPath, $pdfBytes);

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($tempPdfPath);
            $pdf->SetAutoPageBreak(false);

            $line = $declarationTokens !== [] ? implode(', ', $declarationTokens) : '(sem produtos)';

            // Reimporta TODAS as páginas originais (etiqueta de lote com
            // múltiplos volumes pode vir com mais de uma, ver histórico do
            // Mercado Envios Full) e sobrepõe a mesma faixa em cada uma —
            // todo volume pertence ao mesmo pedido.
            for ($i = 1; $i <= $pageCount; $i++) {
                $templateId = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);

                // Desloca só a PRIMEIRA página ~10px pra direita — ela vinha
                // colada na borda esquerda cortando o "Destinatário"
                // impresso ali, enquanto sobrava espaço em branco do lado
                // direito (achado real, etiquetas anteriores). Área da
                // página não muda, só a posição do conteúdo original nela.
                $leftMarginMm = $i === 1 ? 10 * (25.4 / 96) : 0; // 10px a 96dpi ≈ 2.65mm
                $pdf->useTemplate($templateId, $leftMarginMm, 0);

                $this->drawDeclarationFooter($pdf, $size, $line);
            }

            return $pdf->Output('S');
        } finally {
            @unlink($tempPdfPath);
        }
    }

    /**
     * Faixa fina e curta de propósito (reserva altura fixa pra até 2
     * linhas, ~10mm no total incluindo margem/linha separadora) — o texto
     * é só "SKU | QTD: NN" por produto, nunca o nome, então cabe numa
     * fração do espaço que a versão anterior (nome completo) precisava.
     * Mais de ~2 linhas de conteúdo (pedido com muitos produtos
     * diferentes) desenha além da faixa reservada; com AutoPageBreak
     * desligado isso só sai cortado pela borda da própria página, nunca
     * cria página nova nem quebra o restante da etiqueta — degradação
     * aceitável pro caso raro, não um crash.
     */
    private function drawDeclarationFooter(Fpdi $pdf, array $size, string $line): void
    {
        $marginSide = 6; // mm
        $marginBottom = 1.5; // mm
        $fontSize = 9;
        $lineHeight = $fontSize * 0.42; // ~3.8mm
        $reservedLines = 2;
        $gapAboveText = 1.2; // mm entre a linha separadora e o texto

        $contentWidth = $size['width'] - (2 * $marginSide);
        $textHeight = $reservedLines * $lineHeight;

        $lineY = $size['height'] - $marginBottom - $gapAboveText - $textHeight;
        $textTop = $lineY + $gapAboveText;

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($marginSide, $lineY, $size['width'] - $marginSide, $lineY);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($marginSide, $textTop);
        $pdf->SetFont('Arial', 'B', $fontSize);
        $pdf->MultiCell($contentWidth, $lineHeight, $this->toLatin1($line), 0, 'C');
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

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
     * @param  string|null  $scheduledLine  pedido explícito 2026-08-17 —
     *         2ª linha opcional, abaixo da declaração de SKU, só pra venda
     *         com entrega programada ("Pedido agendado dia dd/mm/yyyy |
     *         Pedido nº X"). Existe porque a venda já saiu mas a etiqueta
     *         real só é liberada perto da data agendada — sem essa linha
     *         impressa junto, não dá pra saber olhando a etiqueta (quando
     *         ela finalmente sai) que aquele pedido específico é um caso
     *         agendado, informação que ajuda a conferência do operador.
     * @param  'first'|'last'  $targetPage  BUG REAL 2026-08-21: até aqui a
     *         faixa era desenhada em TODAS as páginas — funciona pra Shopee
     *         (etiqueta de 1 página só, sem sobrar espaço nenhum antes),
     *         mas a etiqueta do Mercado Livre real tem 2 páginas (a etiqueta
     *         de envio em si, colada até a borda inferior — sem espaço
     *         livre nenhum, "Complemento"/QR code chegam quase no fim — e
     *         um DANFE simplificado numa 2ª página, com uma seção "DADOS
     *         ADICIONAIS" vazia, feita sob medida pra isso) — desenhar a
     *         faixa na 1ª página do ML atropelava o endereço real, ilegível.
     *         'first' (Shopee/TikTok, default — só têm 1 página mesmo, sem
     *         mudança de comportamento) desenha só na 1ª; 'last' (Mercado
     *         Livre) desenha só na ÚLTIMA — a DANFE simplificada, que tem
     *         espaço de sobra — mesmo que o PDF só tenha 1 página nesse caso
     *         (cai pra 1ª automaticamente, nunca quebra).
     */
    public function overlayDeclarationFooter(string $pdfBytes, array $declarationTokens, ?string $scheduledLine = null, string $targetPage = 'first'): string
    {
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'label_source_').'.pdf';
        file_put_contents($tempPdfPath, $pdfBytes);

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($tempPdfPath);
            $pdf->SetAutoPageBreak(false);

            $line = $declarationTokens !== [] ? implode(', ', $declarationTokens) : '(sem produtos)';
            $declarationPageNumber = $targetPage === 'last' ? $pageCount : 1;

            // Reimporta TODAS as páginas originais sem alterar nenhuma
            // (etiqueta de lote com múltiplos volumes pode vir com mais de
            // uma, ver histórico do Mercado Envios Full; a etiqueta do ML
            // sempre vem com a DANFE simplificada numa 2ª página) — só a
            // página escolhida por $targetPage ganha a faixa sobreposta.
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

                if ($i === $declarationPageNumber) {
                    $this->drawDeclarationFooter($pdf, $size, $line, $scheduledLine);
                }
            }

            return $pdf->Output('S');
        } finally {
            @unlink($tempPdfPath);
        }
    }

    /**
     * Pedido explícito 2026-08-21 (substitui overlayDeclarationFooter() no
     * fluxo automático, ver LabelFetchService — o método antigo continua
     * aqui, ainda usado pelas telas manuais de teste): em vez de sobrepor a
     * declaração faixa fina por cima da própria etiqueta (risco real de
     * colisão — foi exatamente isso que atropelou o endereço numa etiqueta
     * real do Mercado Livre), gera UMA etiqueta física só, dividida ao meio
     * por uma linha vertical — metade esquerda com a etiqueta original
     * (só a 1ª página, encolhida mantendo proporção, nunca esticada/
     * espremida — um código de barras distorcido não escaneia), metade
     * direita dedicada só à declaração de conteúdo, com espaço de sobra
     * (nada de faixa fina). Página(s) extra da etiqueta original — ex.: a
     * DANFE simplificada do Mercado Livre — não vão mais pro papel térmico
     * físico (decisão explícita do usuário: só a etiqueta de envio importa
     * pra quem embala; a etiqueta ORIGINAL intacta, com a DANFE, continua
     * arquivada à parte por LabelFetchService::attempt(), ver raw_label_path).
     *
     * @param  array<int, string>  $declarationTokens
     */
    public function composeSideBySideLabel(string $pdfBytes, array $declarationTokens, ?string $scheduledLine = null): string
    {
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'label_source_').'.pdf';
        file_put_contents($tempPdfPath, $pdfBytes);

        try {
            $pdf = new Fpdi();
            $pdf->setSourceFile($tempPdfPath);
            $pdf->SetAutoPageBreak(false);

            // Só a 1ª página (a etiqueta de envio em si) segue pro papel
            // físico — ver docblock acima pro motivo de descartar o resto.
            $templateId = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($templateId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);

            $halfWidth = $size['width'] / 2;

            // Só a largura é passada pro useTemplate() — FPDI escala a
            // altura mantendo a proporção original sozinho (nunca distorce
            // o conteúdo original, ver docblock). Como a etiqueta é mais
            // alta que larga, o resultado ocupa só a parte de cima da
            // metade esquerda — centraliza verticalmente em vez de deixar
            // colado no topo.
            $scaledHeight = $size['height'] * ($halfWidth / $size['width']);
            $topOffset = ($size['height'] - $scaledHeight) / 2;
            $pdf->useTemplate($templateId, 0, $topOffset, $halfWidth);

            $line = $declarationTokens !== [] ? implode(', ', $declarationTokens) : '(sem produtos)';
            $this->drawDeclarationPanel($pdf, $size, $halfWidth, $line, $scheduledLine);

            return $pdf->Output('S');
        } finally {
            @unlink($tempPdfPath);
        }
    }

    /**
     * Painel dedicado (metade direita da etiqueta combinada, ver
     * composeSideBySideLabel()) — bem mais espaço que a faixa fina do
     * rodapé antigo (drawDeclarationFooter()), fonte maior, título
     * "DECLARAÇÃO DE CONTEÚDO" pra deixar claro pra quem embala o que
     * aquele lado da etiqueta é. Mesma degradação aceitável de sempre com
     * AutoPageBreak desligado: pedido com muitos produtos que estoure a
     * altura reservada só corta na borda da página, nunca quebra o resto.
     */
    private function drawDeclarationPanel(Fpdi $pdf, array $size, float $halfWidth, string $line, ?string $scheduledLine = null): void
    {
        $marginSide = 3; // mm
        $panelX = $halfWidth + $marginSide;
        $panelWidth = ($size['width'] - $halfWidth) - (2 * $marginSide);

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($halfWidth, 2, $halfWidth, $size['height'] - 2);

        $pdf->SetTextColor(0, 0, 0);

        $y = 8;
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY($panelX, $y);
        $pdf->MultiCell($panelWidth, 3.5, $this->toLatin1('DECLARAÇÃO DE CONTEÚDO'), 0, 'C');

        $y += 8;
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetXY($panelX, $y);
        $pdf->MultiCell($panelWidth, 4.8, $this->toLatin1($line), 0, 'C');

        if ($scheduledLine !== null) {
            $y += 24; // reserva espaço pro wrap da linha de SKU acima antes de começar esta
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetXY($panelX, $y);
            $pdf->MultiCell($panelWidth, 4, $this->toLatin1($scheduledLine), 0, 'C');
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
     *
     * $scheduledLine (pedido explícito 2026-08-17) reserva mais 1 linha
     * logo abaixo da declaração de SKU — mesma fonte/estilo, só reservada
     * separadamente pra não competir por espaço com o wrap da linha de
     * SKU (que pode ocupar até 2 linhas sozinha num pedido com vários
     * produtos).
     */
    private function drawDeclarationFooter(Fpdi $pdf, array $size, string $line, ?string $scheduledLine = null): void
    {
        $marginSide = 6; // mm
        $marginBottom = 1.5; // mm
        $fontSize = 9;
        $lineHeight = $fontSize * 0.42; // ~3.8mm
        $skuReservedLines = 2;
        $gapAboveText = 1.2; // mm entre a linha separadora e o texto

        $contentWidth = $size['width'] - (2 * $marginSide);
        $totalReservedLines = $skuReservedLines + ($scheduledLine !== null ? 1 : 0);
        $textHeight = $totalReservedLines * $lineHeight;

        $lineY = $size['height'] - $marginBottom - $gapAboveText - $textHeight;
        $textTop = $lineY + $gapAboveText;

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($marginSide, $lineY, $size['width'] - $marginSide, $lineY);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($marginSide, $textTop);
        $pdf->SetFont('Arial', 'B', $fontSize);
        $pdf->MultiCell($contentWidth, $lineHeight, $this->toLatin1($line), 0, 'C');

        if ($scheduledLine !== null) {
            $pdf->SetXY($marginSide, $textTop + ($skuReservedLines * $lineHeight));
            $pdf->SetFont('Arial', 'B', $fontSize);
            $pdf->MultiCell($contentWidth, $lineHeight, $this->toLatin1($scheduledLine), 0, 'C');
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

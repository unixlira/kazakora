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
     * Teto pra faixa de declaração de conteúdo — nunca deixa ela comer mais
     * que 40% da etiqueta térmica (10x15cm real, ver
     * LabelProcessingService::extractLabelSizeInInches()), não importa
     * quantos itens o pedido tenha. Sem esse teto, um pedido com muitos
     * itens diferentes cresceria a faixa pra cima até invadir código de
     * barras/endereço — exatamente o bug que os commits a7a595d/d7ac233 já
     * corrigiram uma vez pro caso comum; isso cobre o caso extremo.
     */
    private const MAX_BAND_HEIGHT_RATIO = 0.40;

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
     * Adiciona a declaração de conteúdo (cabeçalho + lista de produtos,
     * quantidade em destaque) no rodapé da etiqueta, abaixo de uma linha
     * separadora, sem tocar em mais nada da etiqueta original (FPDI importa
     * a página como template e só desenha por cima — nenhum retângulo de
     * fundo é pintado, então nada da etiqueta original é apagado, mesmo que
     * a faixa calculada não fique exatamente onde deveria).
     *
     * Existe pra separador reduzir erro de quantidade errada enviada (causa
     * real de vários pedidos errados na implantação inicial) — quem embala
     * confere a etiqueta impressa contra o pedido físico antes de fechar a
     * caixa, em vez de confiar de cabeça na tela do sistema.
     *
     * Fica sempre colada na borda inferior, com fonte pequena (mesma ordem
     * de grandeza do DANFE simplificado, não do texto principal da
     * etiqueta) — se a lista tiver mais de 6 produtos, reduz a fonte e
     * divide em colunas (grid) pra não crescer verticalmente e invadir a
     * área do código de barras/QR/endereço, que ficam sempre acima dessa
     * faixa; ver MAX_BAND_HEIGHT_RATIO/fitDeclarationLayout() pro teto que
     * garante isso mesmo em pedidos com muitos itens diferentes (trunca a
     * lista com um "+N itens" em vez de estourar a faixa).
     *
     * @param  array<int, string>  $productNames  já formatadas pelo chamador (ex.: "2x SKU123 — Nome do produto")
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

            // Desloca a etiqueta original inteira ~10px pra direita — ela vinha
            // colada na borda esquerda cortando o "Destinatário" impresso ali,
            // enquanto sobrava espaço em branco do lado direito. Área da
            // página não muda (mesma largura/altura), só a posição do
            // conteúdo original dentro dela.
            $leftMarginMm = 10 * (25.4 / 96); // 10px a 96dpi ≈ 2.65mm
            $pdf->useTemplate($templateId, $leftMarginMm, 0);

            $names = $productNames !== [] ? $productNames : ['(sem produtos)'];

            $marginSide = 6; // mm — mais folga que antes: 3mm cortava o "Destinatário" impresso perto da borda esquerda
            $marginBottom = 2; // mm
            $lineGap = 1.5; // mm entre a linha separadora e o texto
            $headerHeight = 3; // mm — linha do título "DECLARAÇÃO DE CONTEÚDO"

            $maxBandHeight = $size['height'] * self::MAX_BAND_HEIGHT_RATIO;
            $maxListHeight = max(0.0, $maxBandHeight - $headerHeight - $lineGap - 1);
            [$columns, $fontSize, $names] = $this->fitDeclarationLayout($names, $maxListHeight);

            $lineHeight = $fontSize * 0.42; // mm — compacto, do tamanho do texto miúdo do DANFE simplificado
            $itemsPerColumn = (int) ceil(count($names) / $columns);
            $listHeight = $itemsPerColumn * $lineHeight;
            $bandHeight = $headerHeight + $lineGap + $listHeight + 1;

            $bandTop = $size['height'] - $marginBottom - $bandHeight;
            $lineY = $bandTop + $headerHeight;
            $textTop = $lineY + $lineGap;

            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->SetXY($marginSide, $bandTop);
            $pdf->Cell($size['width'] - (2 * $marginSide), $headerHeight, $this->toLatin1('DECLARAÇÃO DE CONTEÚDO — CONFERIR QTD ANTES DE EMBALAR'), 0, 0, 'C');

            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.3);
            $pdf->Line($marginSide, $lineY, $size['width'] - $marginSide, $lineY);

            $pdf->SetFont('Arial', 'B', $fontSize);

            $columnWidth = ($size['width'] - (2 * $marginSide)) / $columns;

            foreach ($names as $index => $name) {
                $column = (int) floor($index / $itemsPerColumn);
                $row = $index % $itemsPerColumn;

                $pdf->SetXY($marginSide + ($column * $columnWidth), $textTop + ($row * $lineHeight));
                $pdf->Cell($columnWidth - 1, $lineHeight, $this->toLatin1($name), 0, 0, 'C');
            }

            return $pdf->Output('S');
        } finally {
            @unlink($tempPdfPath);
        }
    }

    /**
     * Decide colunas + tamanho de fonte pra lista de produtos caber em
     * $maxListHeight (já descontado cabeçalho/linha/margens pelo chamador).
     * Tenta 1 coluna/8pt, depois 2/7pt, depois 3/6pt; se nem assim couber
     * (pedido com muitos itens diferentes), trunca a lista e acrescenta um
     * resumo "+N itens" — nunca deixa a faixa estourar o teto e invadir o
     * resto da etiqueta.
     *
     * @param  array<int, string>  $names
     * @return array{0: int, 1: int, 2: array<int, string>}
     */
    private function fitDeclarationLayout(array $names, float $maxListHeight): array
    {
        $layouts = [[1, 8], [2, 7], [3, 6]];

        foreach ($layouts as [$columns, $fontSize]) {
            $lineHeight = $fontSize * 0.42;
            $itemsPerColumn = (int) ceil(count($names) / $columns);

            $isLastLayout = $columns === 3;

            if (($itemsPerColumn * $lineHeight) <= $maxListHeight || $isLastLayout) {
                $maxItemsPerColumn = max(1, (int) floor($maxListHeight / $lineHeight));
                $maxItems = $maxItemsPerColumn * $columns;

                if (count($names) > $maxItems) {
                    $shown = array_slice($names, 0, max(1, $maxItems - 1));
                    $hidden = count($names) - count($shown);
                    $shown[] = "+{$hidden} ".($hidden === 1 ? 'item' : 'itens');
                    $names = $shown;
                }

                return [$columns, $fontSize, $names];
            }
        }

        return [3, 6, $names]; // inalcançável — o loop acima sempre retorna no último layout
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

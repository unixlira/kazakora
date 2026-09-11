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
/**
 * FPDF não gira conteúdo por padrão. Esta é a extensão clássica: emite a
 * matriz de transformação direto no fluxo da página, entre q/Q.
 *
 * Existe porque a impressora térmica tem mídia 101,6 x 152,4 EM PÉ. Mandar
 * uma página 152,4 x 101,6 deitada faz o driver tentar encaixar sozinho e
 * sair torto — foi o que aconteceu no teste de 2026-09-06. A página tem
 * que ter o tamanho da etiqueta, e o CONTEÚDO é que vem girado.
 */
class PdfRotativo extends Fpdi
{
    public function iniciarRotacao(float $graus, float $x, float $y): void
    {
        $rad = $graus * M_PI / 180;
        $cos = cos($rad);
        $sen = sin($rad);
        $cx = $x * $this->k;
        $cy = ($this->h - $y) * $this->k;

        $this->_out(sprintf(
            'q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',
            $cos, $sen, -$sen, $cos, $cx, $cy, -$cx, -$cy,
        ));
    }

    public function terminarRotacao(): void
    {
        $this->_out('Q');
    }
}

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
    /**
     * Altura real de um elemento ZPL, em dots.
     *
     * Existe pra compactar sem encavalar. Os dois valores que mais
     * importam são o do QR e o da caixa: o QR do Mercado Livre é
     * `^BQN,2,10` e ocupa 226 dots — estimar 22 (o padrão de texto) fazia
     * o elemento seguinte subir por cima dele. **O QR nunca pode ser
     * cortado nem coberto**, é o que a transportadora lê na coleta.
     */
    private function alturaDoElemento(string $trecho): int
    {
        $altura = 22;

        if (preg_match('/\^A0N,(\d+),/', $trecho, $m)) {
            $altura = (int) $m[1] + 4;
        }

        if (preg_match('/\^GB(\d+),(\d+),/', $trecho, $m)) {
            $altura = max($altura, (int) $m[2]);
        }

        if (preg_match('/\^BC[NRIB]?,(\d+)/', $trecho, $m)) {
            $altura = max($altura, (int) $m[1] + 34);
        }

        // ^BQ<orientacao>,<modelo>,<magnificacao> — o lado do QR sai em
        // torno de 25 módulos vezes a magnificação.
        if (preg_match('/\^BQ[NRIB]?,\d+,(\d+)/', $trecho, $m)) {
            $altura = max($altura, (int) $m[1] * 25);
        }

        if (str_contains($trecho, '^GFA')) {
            $altura = max($altura, 90);
        }

        return $altura;
    }

    /**
     * Texto num ^FB de UMA linha é truncado em silêncio pelo ZPL quando
     * não cabe. Uma rota mais longa que o normal
     * ("XSP16 > SSP53 > ABC99 > 12") sumiria pela direita sem erro
     * nenhum. Reduz a fonte até caber, com piso de 14 dots pra não virar
     * ilegível.
     */
    private function encolherSeNaoCouber(string $trecho): string
    {
        if (! preg_match('/\^FB(\d+),1,/', $trecho, $fb)) {
            return $trecho;
        }

        if (! preg_match('/\^A0N,(\d+),(\d+)/', $trecho, $fonte)) {
            return $trecho;
        }

        if (! preg_match('/\^FD(.*?)(?:\^FS|$)/s', $trecho, $dado)) {
            return $trecho;
        }

        $caracteres = mb_strlen(trim($dado[1]));

        if ($caracteres === 0) {
            return $trecho;
        }

        $largura = (int) $fb[1];
        $alturaFonte = (int) $fonte[1];
        $larguraFonte = (int) $fonte[2];

        // ^A0 é proporcional: o avanço médio fica em torno de 62% do
        // parâmetro de largura.
        $necessario = $caracteres * $larguraFonte * 0.62;

        if ($necessario <= $largura) {
            return $trecho;
        }

        $fator = max(14 / $alturaFonte, $largura / $necessario);

        return preg_replace(
            '/\^A0N,\d+,\d+/',
            sprintf('^A0N,%d,%d', max(14, (int) floor($alturaFonte * $fator)), max(14, (int) floor($larguraFonte * $fator))),
            $trecho,
            1,
        );
    }

    /**
     * Tira o espaço morto vertical de um bloco ZPL sem redimensionar
     * NADA: os campos só são reaproximados, mantendo fonte, altura de
     * código de barras e ^BY intactos.
     *
     * É o que torna a etiqueta combinada viável. Medido numa etiqueta
     * real do Mercado Livre (pedido 1481): a DANFE tinha 47 mm de papel
     * em branco no meio e caiu de 152,4 para 88,9 mm; a etiqueta caiu de
     * 152,4 para 141,1 mm. Sem isso, encaixar as duas metades exigiria
     * 66,7% de escala, que põe a barra fina do código da transportadora
     * em 0,226 mm — abaixo dos 0,25 mm que o leitor laser exige, e foi
     * exatamente o que reprovou a tentativa de 2026-08-21.
     *
     * @return array{0: string, 1: int} ZPL compactado e a altura em dots
     */
    public function compactarZpl(string $bloco, int $vaoMaximo = 20): array
    {
        $trechos = preg_split('/(?=\^FO\d+,\d+)/', $bloco, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        array_shift($trechos);

        $elementos = [];

        foreach ($trechos as $trecho) {
            $trecho = $this->encolherSeNaoCouber($trecho);

            if (! preg_match('/^\^FO(\d+),(\d+)/', $trecho, $m)) {
                continue;
            }

            $elementos[] = ['x' => (int) $m[1], 'y' => (int) $m[2], 'altura' => $this->alturaDoElemento($trecho), 'zpl' => $trecho];
        }

        if ($elementos === []) {
            throw new RuntimeException('Bloco ZPL sem elementos posicionáveis.');
        }

        usort($elementos, fn (array $a, array $b) => $a['y'] <=> $b['y']);

        $linhas = [];

        foreach ($elementos as $e) {
            $linhas[$e['y']] = max($linhas[$e['y']] ?? 0, $e['altura']);
        }

        ksort($linhas);

        $deslocamento = 0;
        $fimCorrente = 0;
        $mapa = [];

        foreach ($linhas as $y => $altura) {
            if ($fimCorrente > 0) {
                $vao = $y - $fimCorrente;

                if ($vao > $vaoMaximo) {
                    $deslocamento += $vao - $vaoMaximo;
                }
            }

            $mapa[$y] = $y - $deslocamento;

            // MAIOR fim até aqui, não o do último elemento lido: uma caixa
            // de 150 dots seguida de um texto de 30 tem o fim da caixa como
            // limite. Trocar isso encavalava a linha de roteirização em
            // cima do bloco de destino.
            $fimCorrente = max($fimCorrente, $y + $altura);
        }

        $alturaFinal = 16 + max(array_map(fn (int $y) => $mapa[$y] + $linhas[$y], array_keys($linhas)));

        $saida = "^XA\n^CI28\n^MCY\n^PW812\n^LL{$alturaFinal}\n";

        foreach ($elementos as $e) {
            $saida .= preg_replace('/^\^FO'.$e['x'].','.$e['y'].'\b/', '^FO'.$e['x'].','.$mapa[$e['y']], $e['zpl'], 1);
        }

        return [$saida.'^XZ', $alturaFinal];
    }

    /**
     * Etiqueta ÚNICA do Mercado Livre: uma folha 10x15 em paisagem, com a
     * etiqueta de envio na metade esquerda, uma linha divisória e a DANFE
     * simplificada na direita — cada metade em retrato.
     *
     * Só faz sentido pro ML de DUAS páginas (Mercado Envios / Coleta), que
     * é onde hoje saem 2 folhas por pedido. O Flex vem com um bloco só e
     * não passa por aqui.
     *
     * A tentativa de 2026-08-21 (composeSideBySideLabel(), revertida 2x)
     * falhou por espremer a etiqueta inteira a 66,7%, o que põe a barra
     * fina em 0,226 mm — abaixo dos 0,25 mm do leitor laser. A diferença
     * aqui é compactarZpl() ANTES: tirando o espaço morto, a escala sobe
     * pra ~71% e a barra fica em 0,285 mm. Medido na etiqueta real do
     * pedido 1481.
     *
     * Alinhado no TOPO, não centralizado: a DANFE é mais curta que a
     * etiqueta, e centralizar deixava uma faixa branca inútil em cima dela.
     */
    public function composeMercadoLivreCombinada(string $zpl): string
    {
        $blocos = preg_split('/(?=\^XA)/', $zpl, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($blocos) < 2) {
            throw new RuntimeException('ZPL do Mercado Livre não tem os 2 blocos (etiqueta + DANFE).');
        }

        $pdfs = [];

        foreach ([$blocos[0], $blocos[1]] as $indice => $bloco) {
            [$compacto, $altura] = $this->compactarZpl($bloco);
            [$compacto, $larguraNativa] = $this->engrossarCodigoDeBarras($compacto, $indice === 1, $altura);

            $pdfs[] = $this->converterCompactoParaPdf($compacto, $altura, $larguraNativa);
        }

        $largura = 152.4;
        $alturaFolha = 101.6;
        $margem = 0.6;

        // Divisão ASSIMÉTRICA, não meio a meio. A etiqueta de envio é
        // limitada pela ALTURA (141 mm de conteúdo em 100,4 mm de folha),
        // então ceder largura pra ela não muda a escala em nada até ~71 mm.
        // A DANFE é limitada pela LARGURA, e cada milímetro a mais engrossa
        // a barra dela. Os 4,8 mm que a etiqueta cede saem de graça e foram
        // o que levou o código da DANFE de 0,212 pra 0,254 mm — o mesmo
        // valor do código da transportadora, que leu no teste físico.
        $divisao = 71.4;
        $metades = [[0.0, $divisao], [$divisao, $largura - $divisao]];

        $arquivos = [];

        try {
            // Página do TAMANHO DA ETIQUETA (retrato). O conteúdo é que
            // gira — ver PdfRotativo. A folha deitada de antes saía torta
            // na impressora térmica.
            $pdf = new PdfRotativo;
            $pdf->SetAutoPageBreak(false);
            $pdf->AddPage('P', [$alturaFolha, $largura]);

            // Girando 90° em torno do centro, um retângulo 152,4 x 101,6
            // encaixa exatamente na página 101,6 x 152,4. A origem do
            // desenho passa a ser este deslocamento.
            $centroX = $alturaFolha / 2;
            $centroY = $largura / 2;
            $pdf->iniciarRotacao(90, $centroX, $centroY);

            $deslocX = $centroX - $largura / 2;
            $deslocY = $centroY - $alturaFolha / 2;

            foreach ($pdfs as $indice => $bytes) {
                [$x0, $larguraMetade] = $metades[$indice];

                $arquivo = tempnam(sys_get_temp_dir(), 'ml_meia_').'.pdf';
                file_put_contents($arquivo, $bytes);
                $arquivos[] = $arquivo;

                $pdf->setSourceFile($arquivo);
                $template = $pdf->importPage(1);
                $tamanho = $pdf->getTemplateSize($template);

                $escala = min(
                    ($larguraMetade - $margem * 2) / $tamanho['width'],
                    ($alturaFolha - $margem * 2) / $tamanho['height'],
                );

                $w = $tamanho['width'] * $escala;
                $h = $tamanho['height'] * $escala;

                // Alinhado no TOPO: a DANFE é mais curta e centralizar
                // deixava faixa branca inútil em cima dela.
                $pdf->useTemplate($template, $deslocX + $x0 + ($larguraMetade - $w) / 2, $deslocY + $margem, $w, $h);
            }

            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.3);
            $pdf->Line($deslocX + $divisao, $deslocY + 3, $deslocX + $divisao, $deslocY + $alturaFolha - 3);

            $pdf->terminarRotacao();

            $saida = $pdf->Output('S');

            // GARANTIA de 1 folha só: é o ponto inteiro desta feature. Se
            // por qualquer motivo sair mais de uma página, é melhor abortar
            // e deixar o caminho normal assumir do que imprimir 2 etiquetas
            // achando que economizou. O chamador trata a exceção caindo pro
            // convertZplToPdf() de sempre.
            $paginas = $this->contarPaginas($saida);

            if ($paginas !== 1) {
                throw new RuntimeException("Etiqueta combinada saiu com {$paginas} páginas — esperado exatamente 1.");
            }

            return $saida;
        } finally {
            foreach ($arquivos as $arquivo) {
                @unlink($arquivo);
            }
        }
    }

    private function contarPaginas(string $pdfBytes): int
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'conta_pag_').'.pdf';
        file_put_contents($arquivo, $pdfBytes);

        try {
            return (new Fpdi)->setSourceFile($arquivo);
        } finally {
            @unlink($arquivo);
        }
    }

    /**
     * O ZPL compactado declara ^PW/^LL próprios, então o tamanho pedido ao
     * Labelary vem deles — não do 4x6 fixo de extractLabelSizeInInches().
     */
    /**
     * Engrossa a barra até o código ocupar a largura inteira da metade.
     *
     * TESTE FÍSICO 2026-09-06: com a etiqueta girada, o QR leu e os dois
     * códigos de barras NÃO. A causa é orientação — girando o conteúdo, as
     * barras passam a correr no sentido do AVANÇO do papel ("escada"),
     * onde a térmica depende do passo do motor, em vez de no sentido do
     * cabeçote ("cerca"), onde cada elemento é um ponto fixo. Barra mais
     * grossa é a compensação direta.
     *
     * A barra final vale `^BY x 0,125mm x (largura da metade / largura
     * nativa)`. Aumentar o ^BY e a largura nativa JUNTOS não adianta nada:
     * o ganho se cancela na divisão (erro cometido na primeira tentativa).
     * O ganho real vem de a tela nativa ser do tamanho do CÓDIGO, e não da
     * folha — o código do ML ocupava 78 dos 101,6 mm da tela, e esses
     * 23 mm de sobra eram escala jogada fora.
     *
     * A largura é MEDIDA, não estimada: renderiza numa tela folgada, acha a
     * coluna mais à direita com tinta e re-renderiza no tamanho exato.
     * Estimar módulos de Code 128 pelo número de dígitos erra feio quando o
     * canal muda o conteúdo do código.
     *
     * @return array{0: string, 1: int} ZPL e a largura nativa em dots
     */
    private function engrossarCodigoDeBarras(string $zpl, bool $ehDanfe, int $alturaEmDots): array
    {
        $de = $ehDanfe ? 2 : 3;
        $para = $de + 1;
        $larguraBase = 812;

        if (! str_contains($zpl, "^BY{$de}")) {
            return [$zpl, $larguraBase];
        }

        $zpl = preg_replace('/\^BY'.$de.'(,|\^)/', "^BY{$para}$1", $zpl, 1);

        // Altura de barra menor na DANFE, a pedido do usuário: pode
        // engrossar e diminuir a altura, desde que não gire.
        if ($ehDanfe) {
            $zpl = preg_replace('/\^BC([NRIB]?),150,/', '^BC$1,110,', $zpl, 1);
        }

        // Mede o código na tela ORIGINAL, antes de mexer no ^PW: numa tela
        // folgada os campos centralizados/à direita se espalham e a medida
        // vira a do texto, não a do código (erro cometido na 1ª tentativa).
        $larguraAtual = $this->medirLarguraDoCodigo(
            $this->converterCompactoParaPdf(
                preg_replace('/\^BY'.$para.'(,|\^)/', "^BY{$de}$1", $zpl, 1),
                $alturaEmDots,
                $larguraBase,
            ),
        );

        // O código cresce na proporção do ^BY. A tela passa a ser do
        // tamanho DELE — é essa folga eliminada que vira barra mais grossa.
        $larguraNativa = max($larguraBase, (int) ceil($larguraAtual * $para / $de) + 8);

        $zpl = preg_replace('/\^PW\d+/', "^PW{$larguraNativa}", $zpl, 1);

        return [$zpl, $larguraNativa];
    }

    /**
     * Largura do código de barras, em dots de 203 dpi.
     *
     * Acha o código pela assinatura que só ele tem: a MESMA sequência de
     * barras repetida por dezenas de linhas seguidas. Texto muda de linha
     * pra linha; código de barras não.
     */
    private function medirLarguraDoCodigo(string $pdfBytes): int
    {
        $imagick = new \Imagick;
        $imagick->setResolution(203, 203);
        $imagick->readImageBlob($pdfBytes);
        $imagick->setImageBackgroundColor('white');
        $imagick = $imagick->flattenImages();
        $imagick->transformImageColorspace(\Imagick::COLORSPACE_GRAY);

        $largura = $imagick->getImageWidth();
        $altura = $imagick->getImageHeight();
        $assinaturas = [];

        for ($y = 2; $y < $altura - 2; $y += 2) {
            $faixa = clone $imagick;
            $faixa->cropImage($largura, 1, 0, $y);

            $inicio = null;
            $fim = null;
            $transicoes = 0;
            $escuroAntes = false;
            $x = 0;

            foreach ($faixa->getPixelIterator() as $linha) {
                foreach ($linha as $pixel) {
                    $escuro = $pixel->getColorValue(\Imagick::COLOR_RED) < 0.5;

                    if ($escuro) {
                        $inicio ??= $x;
                        $fim = $x;
                    }

                    if ($escuro !== $escuroAntes) {
                        $transicoes++;
                        $escuroAntes = $escuro;
                    }

                    $x++;
                }
            }

            $faixa->destroy();

            if ($transicoes < 40 || $inicio === null) {
                continue;
            }

            $chave = $transicoes.':'.$inicio.':'.$fim;
            $assinaturas[$chave] = ($assinaturas[$chave] ?? 0) + 1;
        }

        $imagick->destroy();

        if ($assinaturas === []) {
            return $largura;
        }

        arsort($assinaturas);
        $vencedora = array_key_first($assinaturas);

        // Menos de ~10 linhas iguais não é código de barras, é ruído.
        if ($assinaturas[$vencedora] < 10) {
            return $largura;
        }

        [, $inicio, $fim] = explode(':', $vencedora);

        return (int) $fim - (int) $inicio;
    }

    private function converterCompactoParaPdf(string $zpl, int $alturaEmDots, int $larguraEmDots = 812): string
    {
        $largura = number_format($larguraEmDots / 203, 2, '.', '');
        $altura = number_format($alturaEmDots / 203, 2, '.', '');

        $response = Http::withHeaders(['Accept' => 'application/pdf'])
            ->withBody($zpl, 'application/x-www-form-urlencoded')
            ->post('http://api.labelary.com/v1/printers/'.self::DENSITY_DPMM."dpmm/labels/{$largura}x{$altura}/");

        if ($response->failed()) {
            throw new RuntimeException('Labelary recusou o ZPL compactado: '.$response->status().' — '.$response->body());
        }

        return $response->body();
    }

    /**
     * Teto da API pública da Labelary por chamada — conta blocos ^XA...^XZ
     * E as cópias pedidas em ^PQ.
     */
    private const LABELARY_MAX_ETIQUETAS = 50;

    /**
     * BUG REAL 2026-09-11: 150 etiquetas 2,5x5 pro Full davam "413 — Maximum
     * label count (50) exceeded" e nada saía. Acima de 50, o ZPL é quebrado
     * em lotes de até 50 etiquetas, cada lote vira um PDF e as páginas são
     * juntadas na ordem, num PDF só. Até 50 continua sendo a mesma chamada
     * única de sempre, com o ZPL intacto.
     */
    public function convertZplToPdf(string $zpl): string
    {
        [$width, $height] = $this->extractLabelSizeInInches($zpl);

        $lotes = $this->dividirZplEmLotes($zpl, self::LABELARY_MAX_ETIQUETAS);

        if (count($lotes) === 1) {
            return $this->converterLoteNaLabelary($lotes[0], $width, $height);
        }

        $pdfs = [];

        foreach ($lotes as $indice => $lote) {
            // A Labelary pública limita requisições por segundo; lotes em
            // rajada voltam 429.
            if ($indice > 0) {
                usleep(400_000);
            }

            $pdfs[] = $this->converterLoteNaLabelary($lote, $width, $height);
        }

        return $this->juntarPdfs($pdfs);
    }

    private function converterLoteNaLabelary(string $zpl, float $width, float $height): string
    {
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
     * Quebra o ZPL em lotes de no máximo $max etiquetas.
     *
     * - Cada bloco ^XA...^XZ conta como 1, ou como a quantidade do seu ^PQ.
     * - Bloco com ^PQ maior que o espaço do lote é repetido com ^PQ menor
     *   (^PQ150 vira ^PQ50 três vezes) — a etiqueta sai igual, só a conta
     *   de cópias é dividida.
     * - O que está FORA dos blocos (download de imagem ~DG/~DY que os
     *   blocos usam) vai na frente de todo lote: sem ele, o lote 2 em diante
     *   sairia sem a imagem.
     *
     * @return array<int, string>
     */
    private function dividirZplEmLotes(string $zpl, int $max): array
    {
        if (! preg_match_all('/\^XA.*?\^XZ/s', $zpl, $encontrados)) {
            return [$zpl];
        }

        $blocos = $encontrados[0];
        $quantidade = fn (string $bloco): int => preg_match('/\^PQ(\d+)/', $bloco, $pq) ? max(1, (int) $pq[1]) : 1;

        if (array_sum(array_map($quantidade, $blocos)) <= $max) {
            return [$zpl];
        }

        $fora = trim(str_replace($blocos, '', $zpl));
        $prefixo = $fora !== '' ? $fora."\n" : '';

        $lotes = [];
        $atual = [];
        $noLote = 0;

        foreach ($blocos as $bloco) {
            $restante = $quantidade($bloco);

            while ($restante > 0) {
                if ($noLote === $max) {
                    $lotes[] = $atual;
                    $atual = [];
                    $noLote = 0;
                }

                $parte = min($restante, $max - $noLote);
                $atual[] = $parte === $quantidade($bloco)
                    ? $bloco
                    : (preg_match('/\^PQ\d+/', $bloco)
                        ? preg_replace('/\^PQ\d+/', "^PQ{$parte}", $bloco, 1)
                        : $bloco);
                $noLote += $parte;
                $restante -= $parte;
            }
        }

        if ($atual !== []) {
            $lotes[] = $atual;
        }

        return array_map(fn (array $lote) => $prefixo.implode("\n", $lote), $lotes);
    }

    /**
     * Junta os PDFs dos lotes na ordem, página por página, mantendo o
     * tamanho de cada página (etiqueta 2,5x5 continua 2,5x5).
     *
     * @param  array<int, string>  $pdfs
     */
    private function juntarPdfs(array $pdfs): string
    {
        $final = new Fpdi();
        $final->SetAutoPageBreak(false);
        $temporarios = [];

        try {
            foreach ($pdfs as $bytes) {
                $arquivo = tempnam(sys_get_temp_dir(), 'labelary_lote_').'.pdf';
                file_put_contents($arquivo, $bytes);
                $temporarios[] = $arquivo;

                $paginas = $final->setSourceFile($arquivo);

                for ($pagina = 1; $pagina <= $paginas; $pagina++) {
                    $modelo = $final->importPage($pagina);
                    $tamanho = $final->getTemplateSize($modelo);
                    $final->AddPage($tamanho['orientation'], [$tamanho['width'], $tamanho['height']]);
                    $final->useTemplate($modelo, 0, 0, $tamanho['width'], $tamanho['height']);
                }
            }

            return $final->Output('S');
        } finally {
            foreach ($temporarios as $arquivo) {
                @unlink($arquivo);
            }
        }
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
     * real do Mercado Livre), gera UMA etiqueta física só (impressora
     * térmica 10x15), dividida ao meio por uma linha vertical — cada
     * metade ESTICADA pra preencher 100% do espaço dela (largura E altura,
     * sem sobrar vazio) — pedido explícito do usuário depois de ver a
     * 1ª versão (só a largura escalada, mantendo proporção, deixava um
     * vão vazio enorme embaixo). Distorce um pouco a proporção original —
     * aceito conscientemente aqui, a alternativa (vão vazio) é pior.
     *
     * Layout deitado (paisagem) — pedido explícito 2026-08-21, reafirmado
     * depois de uma tentativa de reverter pra retrato que também saiu
     * errada: o formato final PRECISA ser paisagem, não retrato. A página
     * combinada nasce em orientação 'L' com o tamanho ORIGINAL (retrato) —
     * o FPDF troca width/height sozinho nesse caso (ver _beginpage() do
     * FPDF: orientação 'L' usa size[1] como largura e size[0] como altura),
     * sem precisar calcular nada na mão aqui. A divisão continua
     * esquerda/direita, texto de cada metade continua na leitura vertical
     * normal (não gira) — só o retângulo da página é que fica mais largo
     * que alto.
     *
     * BUG REAL 2026-08-21 (visto numa etiqueta física do Mercado Livre
     * impressa de verdade): a metade esquerda era esticada pra encher
     * EXATAMENTE metade da largura da página nova — isso espremia a
     * LARGURA da etiqueta original (~25-33% menor que o tamanho real,
     * dependendo da proporção da etiqueta de origem), e código de barras é
     * sensível especificamente a isso: a leitura depende da largura de
     * cada barra, não da altura. Resultado: barras vizinhas colaram umas
     * nas outras, ilegível pro leitor. Corrigido dando pra metade esquerda
     * a LARGURA NATIVA da etiqueta original (nenhum fator de escala na
     * largura, código de barras sai com a largura de barra exatamente
     * igual ao original) — só a ALTURA é comprimida pra caber na página
     * (altura não afeta leitura de código de barras, o leitor só precisa
     * de barra alta o bastante pro feixe cruzar, o que sobra de sobra
     * mesmo comprimido). Efeito colateral aceito: a metade direita
     * (DANFE/declaração) fica com menos espaço que antes, já que a
     * esquerda agora reivindica sua largura real em vez de exatamente
     * metade — tradeoff certo, o código de barras PEQUENO da DANFE
     * simplificada não é o que a transportadora escaneia no fluxo normal
     * (ver drawDeclarationBand()), o código de barras de rastreio real
     * (esquerda) é que precisa ler sem erro.
     *
     * $rightSide controla o que entra na metade direita:
     * - 'danfe' (Mercado Livre): a 2ª página original (DANFE simplificada,
     *   com a chave de acesso) — a declaração de SKU some pra dar lugar a
     *   isso, mas continua entrando como uma faixa fina no rodapé DESSA
     *   metade (mesma área "DADOS ADICIONAIS" que já vem vazia na DANFE
     *   real, sem colidir com nada). Sem 2ª página na origem, cai pro
     *   comportamento de 'declaration' (nunca quebra).
     * - 'external' (Shopee, pedido explícito 2026-08-21): a declaração de
     *   conteúdo REAL, baixada separada do servidor da Shopee (ver
     *   ShopeeDriver::fetchContentDeclaration(), passada aqui em
     *   $rightPdfBytes — PDF diferente do $pdfBytes principal, por isso
     *   precisa de um 2º setSourceFile()). Como o documento real da Shopee
     *   JÁ traz os produtos, NADA é desenhado por cima dele (sem faixa de
     *   SKU/QTD, ao contrário de 'danfe') — só a página 1 dele, esticada
     *   igual à esquerda. Sem $rightPdfBytes (fetch falhou), cai pro
     *   comportamento de 'declaration'.
     * - 'declaration' (fallback / TikTok): painel só com a declaração de
     *   conteúdo desenhada localmente, sem PDF de origem pra mostrar.
     *
     * Página(s)/arquivo(s) além dos usados não vão pro papel físico — a
     * etiqueta ORIGINAL intacta continua arquivada à parte por
     * LabelFetchService::attempt(), ver raw_label_path.
     *
     * @param  array<int, string>  $declarationTokens
     */
    public function composeSideBySideLabel(string $pdfBytes, array $declarationTokens, ?string $scheduledLine = null, string $rightSide = 'declaration', ?string $rightPdfBytes = null): string
    {
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'label_source_').'.pdf';
        file_put_contents($tempPdfPath, $pdfBytes);

        $tempRightPdfPath = null;

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($tempPdfPath);
            $pdf->SetAutoPageBreak(false);

            $leftTemplateId = $pdf->importPage(1);
            $originalSize = $pdf->getTemplateSize($leftTemplateId);

            // Orientação forçada 'L' + tamanho original (retrato) passado
            // como veio — o FPDF troca width/height sozinho nesse caso (ver
            // docblock acima), então a página final já nasce deitada sem
            // cálculo manual aqui.
            $pdf->AddPage('L', [$originalSize['width'], $originalSize['height']]);

            // Página final tem width/height trocados em relação ao PDF de
            // origem — ver docblock. $size local abaixo já reflete isso pra
            // todo o resto do método e pros helpers de desenho.
            $size = ['width' => $originalSize['height'], 'height' => $originalSize['width']];

            // Largura NATIVA da etiqueta original — sem fator de escala
            // nenhum na largura, código de barras sai com a largura de
            // barra idêntica à original (ver docblock). Clamp defensivo:
            // reserva pelo menos 10mm pra metade direita mesmo no caso raro
            // de uma etiqueta de origem já mais larga que alta (aí a troca
            // de orientação não sobra tanto espaço quanto o normal).
            $leftWidth = min($originalSize['width'], $size['width'] - 10);

            // Só a ALTURA é esticada/comprimida pra caber na página —
            // não afeta leitura de código de barras (ver docblock).
            $pdf->useTemplate($leftTemplateId, 0, 0, $leftWidth, $size['height']);

            $line = $declarationTokens !== [] ? implode(', ', $declarationTokens) : '(sem produtos)';

            if ($rightSide === 'danfe' && $pageCount >= 2) {
                $rightTemplateId = $pdf->importPage(2);
                $pdf->useTemplate($rightTemplateId, $leftWidth, 0, $size['width'] - $leftWidth, $size['height']);
                $this->drawDeclarationBand($pdf, $size, $leftWidth, $line, $scheduledLine);
            } elseif ($rightSide === 'external' && $rightPdfBytes !== null) {
                // 2º arquivo de origem — FPDI suporta múltiplos
                // setSourceFile() no mesmo documento de saída, cada
                // importPage() seguinte passa a ler do último arquivo
                // setado.
                $tempRightPdfPath = tempnam(sys_get_temp_dir(), 'label_right_').'.pdf';
                file_put_contents($tempRightPdfPath, $rightPdfBytes);

                $pdf->setSourceFile($tempRightPdfPath);
                $rightTemplateId = $pdf->importPage(1);
                $pdf->useTemplate($rightTemplateId, $leftWidth, 0, $size['width'] - $leftWidth, $size['height']);
                // Sem faixa de SKU/QTD por cima — o documento real da
                // Shopee já lista os produtos, ver docblock.
            } else {
                $this->drawDeclarationPanel($pdf, $size, $leftWidth, $line, $scheduledLine);
            }

            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.3);
            $pdf->Line($leftWidth, 2, $leftWidth, $size['height'] - 2);

            return $pdf->Output('S');
        } finally {
            @unlink($tempPdfPath);

            if ($tempRightPdfPath !== null) {
                @unlink($tempRightPdfPath);
            }
        }
    }

    /**
     * Faixa fina no rodapé da metade direita (usada quando $rightSide é a
     * DANFE, ver composeSideBySideLabel()) — a mesma área "DADOS
     * ADICIONAIS" que já vem vazia na DANFE simplificada real do Mercado
     * Livre, então não colide com o conteúdo dela mesmo esticada.
     */
    private function drawDeclarationBand(Fpdi $pdf, array $size, float $halfWidth, string $line, ?string $scheduledLine = null): void
    {
        $marginSide = 3; // mm
        $marginBottom = 2; // mm
        $fontSize = 7;
        $lineHeight = $fontSize * 0.42;
        $reservedLines = 2 + ($scheduledLine !== null ? 1 : 0);
        $textHeight = $reservedLines * $lineHeight;

        $bandX = $halfWidth + $marginSide;
        $bandWidth = ($size['width'] - $halfWidth) - (2 * $marginSide);
        $textTop = $size['height'] - $marginBottom - $textHeight;

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', $fontSize);
        $pdf->SetXY($bandX, $textTop);
        $pdf->MultiCell($bandWidth, $lineHeight, $this->toLatin1(str_replace('-', '- ', $line)), 0, 'C');

        if ($scheduledLine !== null) {
            $pdf->SetFont('Arial', 'B', $fontSize - 1);
            $pdf->SetXY($bandX, $textTop + (2 * $lineHeight));
            $pdf->MultiCell($bandWidth, $lineHeight, $this->toLatin1($scheduledLine), 0, 'C');
        }
    }

    /**
     * Painel dedicado (metade direita da etiqueta combinada, ver
     * composeSideBySideLabel()) — usado quando não há DANFE de origem
     * (Shopee/TikTok, ou Mercado Livre sem 2ª página). Bem mais espaço que
     * a faixa fina do rodapé antigo (drawDeclarationFooter()), fonte
     * maior, centralizado na altura toda da metade — preenche o espaço em
     * vez de ficar colado no topo. Mesma degradação aceitável de sempre
     * com AutoPageBreak desligado: pedido com muitos produtos que estoure
     * a altura reservada só corta na borda da página, nunca quebra o
     * resto.
     */
    private function drawDeclarationPanel(Fpdi $pdf, array $size, float $halfWidth, string $line, ?string $scheduledLine = null): void
    {
        $marginSide = 3; // mm
        $panelX = $halfWidth + $marginSide;
        $panelWidth = ($size['width'] - $halfWidth) - (2 * $marginSide);

        $pdf->SetTextColor(0, 0, 0);

        // Centralizado verticalmente na metade inteira (não mais colado no
        // topo) — bloco de título + SKU + (linha agendada opcional).
        $blockHeight = 8 + 4.2 * (substr_count($line, ' ') + 3) + ($scheduledLine !== null ? 12 : 0);
        $y = max(8, ($size['height'] - $blockHeight) / 2);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($panelX, $y);
        $pdf->MultiCell($panelWidth, 4.5, $this->toLatin1('DECLARAÇÃO DE CONTEÚDO'), 0, 'C');

        $y += 10;
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetXY($panelX, $y);
        // BUG REAL 2026-08-21 (visto na 1ª etiqueta de teste do layout novo):
        // MultiCell só quebra linha em espaço — um SKU inteiro sem espaço
        // (padrão real do SkuGeneratorService, ex: "ORG-DIS-LCK-ABS-INOX-0001")
        // mais largo que o painel força quebra NO MEIO da palavra ("ABS-IN" /
        // "OX-0001"), ilegível. Insere um espaço depois de cada hífen só
        // pra exibição (não altera o SKU de verdade em lugar nenhum) — dá
        // ponto de quebra natural, sai "ORG- DIS- LCK-..." em vez de cortar
        // qualquer letra ao meio.
        $pdf->MultiCell($panelWidth, 5, $this->toLatin1(str_replace('-', '- ', $line)), 0, 'C');

        if ($scheduledLine !== null) {
            $y += 28; // reserva espaço pro wrap da linha de SKU acima antes de começar esta
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

        // Pedido explícito 2026-08-30: "remove o hr acima do sku e
        // quantidade que sai na impressão" — linha separadora removida,
        // $lineY continua existindo só como referência de posicionamento
        // pro texto abaixo (nenhuma mudança de layout vertical).
        $lineY = $size['height'] - $marginBottom - $gapAboveText - $textHeight;
        $textTop = $lineY + $gapAboveText;

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

<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Troca o logo do Melhor Envio pelo da KazaKora na etiqueta (pedido do Lira
 * em 2026-11-09, árvore #3978). O que a transportadora lê é o código de
 * barras e o código de triagem; o logo é só a marca do Melhor Envio.
 *
 * O PDF do Melhor Envio é uma imagem só e o lugar do logo muda conforme o
 * modelo da transportadora, então a posição vem de uma etiqueta real medida
 * (fração da página). Transportadora sem posição medida sai como veio e fica
 * no log, para medir na primeira etiqueta dela.
 */
class EtiquetaMelhorEnvioComMarca
{
    private const LOGO = 'images/marca/logo-kazakora-fiel.png';

    /**
     * Desenho do favicon da KazaKora (a casa com KK), em cima da palavra
     * KazaKora na etiqueta (pedido do Lira em 21/11/2026).
     */
    private const FAVICON = 'images/marca/favicon-512.png';

    /** Altura do desenho do favicon em relação à altura da palavra KazaKora. */
    private const FAVICON_ESCALA = 1.9;

    /** Margem mínima entre o logo e a borda de cima da etiqueta (fração da página). */
    private const MARGEM_BORDA = 0.05;

    /**
     * Logo da FerraKora para a térmica. Desde 14/11/2026 é o da marca nova
     * (o "FerraKora" verde-limão e índigo do site), todo em preto sobre
     * branco: a térmica imprime uma cor só e o verde sairia claro demais. O
     * antigo (martelo no F, serra no O) saiu na etiqueta do #4162 e o Lira
     * mandou trocar. Arquivo novo de propósito, para nada guardar o velho.
     */
    private const LOGO_FERRAKORA = 'images/marca/logo-ferrakora-etiqueta-v2.png';

    /**
     * Área do logo do Melhor Envio por modelo, em fração da página
     * [x0, y0, x1, y1]. J&T: medida na etiqueta do #3978 (770x1084 px, logo
     * em 97-328 x 866-973), com folga para cobrir o logo inteiro.
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public const AREAS = [
        'jet' => [0.10, 0.785, 0.46, 0.91],
        // Correios (SEDEX/PAC): medida na etiqueta do #4172 (14/11): logo em
        // 0,036-0,317 x 0,078-0,170 e "melhorenvio.com" logo abaixo, até
        // 0,208. A linha "NF:" começa em 0,218 e fica.
        'correios' => [0.03, 0.06, 0.345, 0.2145],
    ];

    /**
     * Primeira linha do remetente, onde o Melhor Envio imprime "Nome, Tel:
     * (11) ..." [x0, y0, x1, y1, linha de base]. O telefone é o do cadastro
     * da empresa (em 14/11, #4172, saiu o pessoal do Lira): a linha é
     * reescrita com o nome e o telefone de atendimento da loja do pedido.
     * Medida na etiqueta do #4172.
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    public const REMETENTE = [
        'correios' => [0.03, 0.7085, 0.97, 0.7335, 0.7298],
    ];

    public static function modelo(?string $servico): ?string
    {
        $s = mb_strtolower((string) $servico);

        return match (true) {
            str_contains($s, 'jet') || str_contains($s, 'j&t') => 'jet',
            str_contains($s, 'correios') || str_contains($s, 'sedex') || preg_match('/\bpac\b/', $s) === 1 => 'correios',
            default => null,
        };
    }

    /** Logo que vai na etiqueta conforme o site do pedido (SiteAtual). */
    public static function logo(?string $site): string
    {
        return $site === \App\Support\SiteAtual::FERRAKORA ? self::LOGO_FERRAKORA : self::LOGO;
    }

    /**
     * Onde entram a linha e a lista "SKU / quantidade" (pedido do Lira em
     * 14/11, #4172): abaixo do remetente, no espaço em branco da etiqueta
     * [x0, y da linha, x1, primeira linha de base, limite de baixo].
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    public const ITENS = [
        'correios' => [0.0365, 0.80, 0.9635, 0.826, 0.965],
        // J&T: rodapé livre abaixo do logo, medido na etiqueta do #4162 (14/11):
        // nada impresso entre 0,90 e 0,989. Cabem duas linhas.
        'jet' => [0.05, 0.915, 0.95, 0.9425, 0.968],
    ];

    /** Nome e telefone da loja na linha do remetente (os mesmos que vão ao Melhor Envio). */
    public static function remetente(?string $site): string
    {
        $nome = $site === \App\Support\SiteAtual::FERRAKORA ? 'FerraKora' : (string) (config('melhorenvio.remetente') ?: 'KazaKora');

        $nome = (string) (@iconv('UTF-8', 'windows-1252//TRANSLIT', $nome) ?: $nome);
        $telefone = self::telefoneFormatado($site);

        return $telefone === '' ? $nome : "{$nome}, Tel: {$telefone}";
    }

    /** Telefone de atendimento da loja do pedido, só dígitos (vai ao Melhor Envio). */
    public static function telefone(?string $site): string
    {
        $loja = $site === \App\Support\SiteAtual::FERRAKORA ? 'ferrakora' : 'kazakora';

        return (string) preg_replace('/\D/', '', (string) config("melhorenvio.telefones.{$loja}"));
    }

    public static function telefoneFormatado(?string $site): string
    {
        $d = self::telefone($site);

        return match (strlen($d)) {
            11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
            10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
            default => $d,
        };
    }

    /**
     * @param  array<int, array{sku: ?string, quantidade: int}>  $itens  o que vai na caixa, para quem separa
     * @param  ?string  $site  site do pedido (SiteAtual): FerraKora sai com o
     *                         logo dela em preto e branco; o resto, KazaKora.
     */
    public function aplicar(string $pdf, ?string $servico, ?int $orderId = null, ?string $site = null, array $itens = []): string
    {
        $layout = $this->layout($pdf, $servico, $orderId);
        if ($layout === null) {
            return $pdf;
        }

        $arquivo = tempnam(sys_get_temp_dir(), 'me_marca_');
        file_put_contents($arquivo, $pdf);

        try {
            $fpdi = new Fpdi('P', 'pt');
            $fpdi->SetAutoPageBreak(false);
            $paginas = $fpdi->setSourceFile($arquivo);
            for ($i = 1; $i <= $paginas; $i++) {
                $modeloPdf = $fpdi->importPage($i);
                $tamanho = $fpdi->getTemplateSize($modeloPdf);
                $w = (float) $tamanho['width'];
                $h = (float) $tamanho['height'];
                $fpdi->AddPage($w > $h ? 'L' : 'P', [$w, $h]);
                $fpdi->useTemplate($modeloPdf, 0, 0, $w, $h);

                if ($i === 1) {
                    $fpdi->SetFillColor(255, 255, 255);
                    foreach ($layout['cobrir'] as [$cx0, $cy0, $cx1, $cy1]) {
                        $fpdi->Rect($cx0 * $w, $cy0 * $h, ($cx1 - $cx0) * $w, ($cy1 - $cy0) * $h, 'F');
                    }

                    if ($layout['logo'] !== null) {
                        [$x0, $y0, $x1, $y1] = $layout['logo'];
                        $logo = public_path(self::logo($site));

                        // Logo centrado na área, ocupando 90% da largura dela (ou
                        // 90% da altura, se o desenho for alto demais para a área).
                        [$lw, $lh] = getimagesize($logo);
                        $largura = ($x1 - $x0) * $w * 0.9;
                        $altura = $largura * $lh / $lw;
                        if ($altura > ($y1 - $y0) * $h * 0.9) {
                            $altura = ($y1 - $y0) * $h * 0.9;
                            $largura = $altura * $lw / $lh;
                        }
                        $centroX = ($x0 + $x1) / 2 * $w;
                        if ($site === \App\Support\SiteAtual::FERRAKORA) {
                            $fpdi->Image($logo, $centroX - $largura / 2, ($y0 + $y1) / 2 * $h - $altura / 2, $largura, $altura);
                        } else {
                            // KazaKora (pedido do Lira em 21/11/2026): desenho do
                            // favicon em cima e a palavra KazaKora embaixo, no
                            // mesmo tamanho de antes, os dois centralizados. O
                            // bloco fica na área do logo e não encosta na borda
                            // de cima da etiqueta.
                            $lado = $altura * self::FAVICON_ESCALA;
                            $vao = $altura * 0.25;
                            $bloco = $lado + $vao + $altura;
                            $topo = max(($y0 + $y1) / 2 * $h - $bloco / 2, self::MARGEM_BORDA * $h);
                            $fpdi->Image(public_path(self::FAVICON), $centroX - $lado / 2, $topo, $lado, $lado);
                            $fpdi->Image($logo, $centroX - $largura / 2, $topo + $lado + $vao, $largura, $altura);
                        }
                    }

                    if ($layout['remetente'] !== null) {
                        [$rx0, $ry0, $rx1, $ry1, $base, $comRotulo] = $layout['remetente'];
                        $fpdi->Rect($rx0 * $w, $ry0 * $h, ($rx1 - $rx0) * $w, ($ry1 - $ry0) * $h, 'F');
                        $fpdi->SetTextColor(0, 0, 0);
                        $recuo = 0.0;
                        if ($comRotulo) {
                            $fpdi->SetFont('Helvetica', 'B', 15);
                            $rotulo = 'REMETENTE: ';
                            $fpdi->Text(0.0365 * $w, $base * $h, $rotulo);
                            $recuo = $fpdi->GetStringWidth($rotulo);
                        }
                        $fpdi->SetFont('Helvetica', '', 15);
                        $fpdi->Text(0.0365 * $w + $recuo, $base * $h, self::remetente($site));
                    }

                    if ($itens !== [] && $layout['itens'] !== null) {
                        [$ix0, $linha, $ix1, $primeira, $limite] = $layout['itens'];
                        $fpdi->SetDrawColor(0, 0, 0);
                        $fpdi->SetLineWidth(1);
                        $fpdi->Line($ix0 * $w, $linha * $h, $ix1 * $w, $linha * $h);
                        $fpdi->SetTextColor(0, 0, 0);
                        $passo = 0.0235;
                        $cabem = max(1, (int) floor(($limite - $primeira) / $passo) + 1);
                        $linhas = array_values($itens);
                        $sobra = count($linhas) - $cabem;
                        if ($sobra > 0) {
                            $linhas = array_slice($linhas, 0, $cabem - 1);
                        }
                        $y = $primeira;
                        foreach ($linhas as $item) {
                            $sku = trim((string) ($item['sku'] ?? '')) ?: 'sem SKU';
                            $fpdi->SetFont('Helvetica', 'B', 14);
                            $fpdi->Text($ix0 * $w, $y * $h, 'SKU: ');
                            $recuo = $fpdi->GetStringWidth('SKU: ');
                            $fpdi->SetFont('Helvetica', '', 14);
                            $texto = (string) (@iconv('UTF-8', 'windows-1252//TRANSLIT', $sku) ?: $sku);
                            $qtd = 'Qtd: '.(int) ($item['quantidade'] ?? 1);
                            $fpdi->Text($ix0 * $w + $recuo, $y * $h, $texto);
                            $fpdi->SetFont('Helvetica', 'B', 14);
                            $fpdi->Text($ix1 * $w - $fpdi->GetStringWidth($qtd), $y * $h, $qtd);
                            $y += $passo;
                        }
                        if ($sobra > 0) {
                            $fpdi->SetFont('Helvetica', '', 14);
                            $fpdi->Text($ix0 * $w, $y * $h, '+ '.($sobra + 1).' itens (ver pedido)');
                        }
                    }
                }
            }

            return $fpdi->Output('S');
        } catch (Throwable $e) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.logo_troca_falhou', ['order_id' => $orderId, 'message' => $e->getMessage()]);

            return $pdf;
        } finally {
            @unlink($arquivo);
        }
    }

    /**
     * O que cobrir e onde escrever. Modelo medido (J&T, Correios) usa as
     * posições medidas; qualquer outra transportadora (Loggi no #4198, Jadlog,
     * LATAM...) é lida por OCR (EtiquetaOcr). null = sai como veio.
     *
     * @return array{cobrir: list<array{0: float, 1: float, 2: float, 3: float}>, logo: ?array{0: float, 1: float, 2: float, 3: float}, remetente: ?array{0: float, 1: float, 2: float, 3: float, 4: float, 5: bool}, itens: ?array{0: float, 1: float, 2: float, 3: float, 4: float}}|null
     */
    private function layout(string $pdf, ?string $servico, ?int $orderId): ?array
    {
        $modelo = self::modelo($servico);
        if ($modelo && isset(self::AREAS[$modelo])) {
            return [
                'cobrir' => [self::AREAS[$modelo]],
                'logo' => self::AREAS[$modelo],
                'remetente' => isset(self::REMETENTE[$modelo]) ? [...self::REMETENTE[$modelo], true] : null,
                'itens' => self::ITENS[$modelo] ?? null,
            ];
        }

        $palavras = app(EtiquetaOcr::class)->palavras($pdf);
        if ($palavras === null) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.logo_sem_posicao', ['order_id' => $orderId, 'servico' => $servico, 'ocr' => 'indisponivel']);

            return null;
        }

        $layout = EtiquetaOcr::layout($palavras);
        Log::channel(config('melhorenvio.log_channel'))->info('melhorenvio.logo_por_ocr', ['order_id' => $orderId, 'servico' => $servico, 'logo' => $layout['logo'], 'remetente' => $layout['remetente'] !== null, 'itens' => $layout['itens'] !== null]);

        return $layout['cobrir'] === [] && $layout['remetente'] === null && $layout['itens'] === null ? null : $layout;
    }
}

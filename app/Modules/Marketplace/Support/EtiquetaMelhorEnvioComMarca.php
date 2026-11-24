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
    /**
     * Palavra KazaKora da etiqueta, toda em preto (21/11/2026): a térmica
     * imprime uma cor só e os dois K laranja do logo colorido sumiam.
     */
    public const LOGO = 'images/marca/logo-kazakora-etiqueta.png';

    /**
     * Símbolo da KazaKora (a casa com KK) em cima da palavra KazaKora na
     * etiqueta (pedido do Lira em 21/11/2026). Só as linhas, fundo
     * transparente, igual ao ícone do menu Oportunidades: a etiqueta é
     * térmica e o quadrado laranja do favicon saía como um bloco preto.
     * Feito do favicon de 512 px: as linhas em preto, que a térmica imprime nítido.
     */
    public const SIMBOLO = 'images/marca/simbolo-kazakora-etiqueta.png';

    /** Altura máxima do bloco símbolo + palavra (fração da página): o tamanho do logo da Loggi. */
    private const ALTURA_BLOCO = 0.072;

    /** Altura da palavra KazaKora em relação à altura do símbolo, na mesma linha. */
    private const PALAVRA_INLINE = 0.55;

    /** Espaço entre o símbolo e a palavra, em relação à altura do símbolo. */
    private const VAO_INLINE = 0.15;

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

    /**
     * Jadlog (.Package / .Package Centralizado), medida na etiqueta real de
     * 24/11/2026 (página de 577,5 x 813 pt, imagem de 770 x 1084 px). O
     * Melhor Envio põe o logo da Jadlog no quadro GRANDE de cima à esquerda
     * (px 28-211 x 84-134; quadro em 11-526 x 41-202) com a linha "ORD-..."
     * embaixo (px 169-181, fica) e o logo dele no quadro PEQUENO à direita
     * (quadro em 527-758 x 41-166, logo em 579-704 x 74-132).
     *
     * Pedido do Lira (24/11): troca de lado. A KazaKora vai para o quadro
     * grande, no lugar do logo da Jadlog, e o logo da Jadlog vai para o
     * quadro pequeno, cobrindo o do Melhor Envio. Tudo com margem das linhas.
     * Com MELHORENVIO_ETIQUETA_LOGO_JADLOG=quadro_pequeno o logo da Jadlog
     * fica onde está e a KazaKora entra no quadro pequeno.
     *
     * A Jadlog não imprime telefone do remetente (o bloco vertical
     * "KazaKora, Rua Mogi Mirim..." não tem; o "Telefone" é do
     * destinatário), então não há linha de remetente a reescrever.
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public const JADLOG = [
        // Faixa do logo da Jadlog no quadro grande (px 14-523 x 44-163): só o logo, nada mais ali.
        'cobrir_grande' => [0.018, 0.041, 0.679, 0.150],
        // Onde entra a KazaKora no quadro grande: 17 px das linhas e acima do ORD (que começa em 0,156).
        'logo_grande' => [0.036, 0.050, 0.660, 0.146],
        // Quadro pequeno inteiro por dentro das linhas (px 530-755 x 44-164).
        'cobrir_pequeno' => [0.689, 0.041, 0.981, 0.151],
        // Área do logo no quadro pequeno, com folga de ~14 px das linhas.
        'logo_pequeno' => [0.7065, 0.0535, 0.965, 0.1385],
    ];

    /** Logo da Jadlog em preto e branco, tirado da própria etiqueta (vai no quadro pequeno). */
    public const LOGO_JADLOG = 'images/marca/logo-jadlog-etiqueta.png';

    public static function modelo(?string $servico): ?string
    {
        $s = mb_strtolower((string) $servico);

        return match (true) {
            str_contains($s, 'jadlog') => 'jadlog',
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
     * [x0, y da linha (null = sem linha), x1, primeira linha de base, limite
     * de baixo]. Com uma linha só (primeira = limite), os itens vão lado a
     * lado nela. MELHORENVIO_ETIQUETA_SKU=desligado não escreve nada.
     *
     * @var array<string, array{0: float, 1: ?float, 2: float, 3: float, 4: float}>
     */
    public const ITENS = [
        // Jadlog: o código de barras principal vai até 5 px antes da linha
        // do quadro (px 937 x 942), sem espaço. O livre de verdade é embaixo
        // do código do rodapé: px 1030-1071 (barras acabam em 1029, a linha
        // do quadro está em 1072). Uma linha de 14 pt (7 pt na folha 4x6),
        // base em 0,973 (px 1055): ~12 px de folga em cima e embaixo.
        'jadlog' => [0.0365, null, 0.9635, 0.973, 0.973],
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

                    if (($layout['transportadora'] ?? null) !== null) {
                        // Logo da transportadora trocado de lugar (Jadlog, 24/11).
                        [$tx0, $ty0, $tx1, $ty1, $arquivoTransp] = $layout['transportadora'];
                        $this->imagemCentrada($fpdi, public_path($arquivoTransp), [$tx0, $ty0, $tx1, $ty1], $w, $h);
                    }

                    if ($layout['logo'] !== null) {
                        [$x0, $y0, $x1, $y1] = $layout['logo'];
                        $logo = public_path(self::logo($site));
                        [$lw, $lh] = getimagesize($logo);
                        // Contido: o bloco não passa da área (quadro fechado, Jadlog).
                        $contido = (bool) ($layout['contido'] ?? false);

                        if ($site === \App\Support\SiteAtual::FERRAKORA) {
                            // Logo centrado na área, em até 90% da largura e da altura dela.
                            $this->imagemCentrada($fpdi, $logo, [$x0, $y0, $x1, $y1], $w, $h);
                        } else {
                            // KazaKora (pedido do Lira em 21/11/2026): símbolo (a
                            // casa com KK, preto e traço grosso) e a palavra
                            // KazaKora na mesma linha, do tamanho do logo da
                            // transportadora. Área do lado direito: encosta à
                            // direita e pode crescer até o meio da etiqueta;
                            // do lado esquerdo: fica na largura da área. Nunca
                            // encosta na borda de cima.
                            $simbolo = public_path(self::SIMBOLO);
                            [$sw, $sh] = getimagesize($simbolo);
                            $direita = ($x0 + $x1) / 2 > 0.5;
                            $maxLargura = $direita && ! $contido ? ($x1 - 0.5) * $w : ($x1 - $x0) * $w;
                            $maxAltura = $contido
                                ? min(self::ALTURA_BLOCO * $h, ($y1 - $y0) * $h)
                                : min(self::ALTURA_BLOCO * $h, max(($y1 - $y0) * $h, self::ALTURA_BLOCO * $h * 0.6));
                            $proporcaoPalavra = $lw / $lh;
                            // Largura do bloco por unidade de altura do símbolo.
                            $porUnidade = $sw / $sh + self::VAO_INLINE + self::PALAVRA_INLINE * $proporcaoPalavra;
                            $altSimbolo = min($maxAltura, $maxLargura / $porUnidade);
                            $largSimbolo = $altSimbolo * $sw / $sh;
                            $altPalavra = $altSimbolo * self::PALAVRA_INLINE;
                            $largPalavra = $altPalavra * $proporcaoPalavra;
                            $vao = $altSimbolo * self::VAO_INLINE;
                            $larguraBloco = $largSimbolo + $vao + $largPalavra;
                            $esquerda = match (true) {
                                $contido && $direita => ($x0 + $x1) / 2 * $w - $larguraBloco / 2,
                                $direita => $x1 * $w - $larguraBloco,
                                default => $x0 * $w,
                            };
                            $topo = $contido
                                ? ($y0 + $y1) / 2 * $h - $altSimbolo / 2
                                : max(($y0 + $y1) / 2 * $h - $altSimbolo / 2, self::MARGEM_BORDA * $h);
                            $fpdi->Image($simbolo, $esquerda, $topo, $largSimbolo, $altSimbolo);
                            // Palavra com a base alinhada à base da casa.
                            $fpdi->Image($logo, $esquerda + $largSimbolo + $vao, $topo + $altSimbolo - $altPalavra, $largPalavra, $altPalavra);
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

                    if ($itens !== [] && $layout['itens'] !== null && self::escreveSku()) {
                        [$ix0, $linha, $ix1, $primeira, $limite] = $layout['itens'];
                        if ($linha !== null) {
                            $fpdi->SetDrawColor(0, 0, 0);
                            $fpdi->SetLineWidth(1);
                            $fpdi->Line($ix0 * $w, $linha * $h, $ix1 * $w, $linha * $h);
                        }
                        $fpdi->SetTextColor(0, 0, 0);
                        $passo = 0.0235;
                        $cabem = max(1, (int) floor(($limite - $primeira) / $passo) + 1);
                        if ($cabem === 1 && count($itens) > 1) {
                            // Uma linha só (Jadlog): os itens lado a lado.
                            $this->itensNumaLinha($fpdi, array_values($itens), $ix0 * $w, $ix1 * $w, $primeira * $h);
                            $itens = [];
                        }
                        $linhas = array_values($itens);
                        $sobra = $linhas === [] ? 0 : count($linhas) - $cabem;
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

    /** SKU e quantidade na etiqueta: MELHORENVIO_ETIQUETA_SKU = abaixo_codigo (padrão) | desligado. */
    public static function escreveSku(): bool
    {
        return mb_strtolower(trim((string) config('melhorenvio.etiqueta.sku', 'abaixo_codigo'))) !== 'desligado';
    }

    /**
     * Jadlog sem OCR, pelas posições medidas (JADLOG). quadro_grande (padrão):
     * KazaKora no lugar do logo da Jadlog e o logo da Jadlog no quadro
     * pequeno, por cima do Melhor Envio. quadro_pequeno: o logo da Jadlog
     * fica e a KazaKora cobre o Melhor Envio.
     *
     * @return array{cobrir: list<array{0: float, 1: float, 2: float, 3: float}>, logo: array{0: float, 1: float, 2: float, 3: float}, contido: bool, transportadora: ?array{0: float, 1: float, 2: float, 3: float, 4: string}, remetente: null, itens: array{0: float, 1: ?float, 2: float, 3: float, 4: float}}
     */
    public static function layoutJadlog(): array
    {
        $j = self::JADLOG;
        if (mb_strtolower(trim((string) config('melhorenvio.etiqueta.logo_loja.jadlog', 'quadro_grande'))) === 'quadro_pequeno') {
            return ['cobrir' => [$j['cobrir_pequeno']], 'logo' => $j['logo_pequeno'], 'contido' => true, 'transportadora' => null, 'remetente' => null, 'itens' => self::ITENS['jadlog']];
        }

        return [
            'cobrir' => [$j['cobrir_grande'], $j['cobrir_pequeno']],
            'logo' => $j['logo_grande'],
            'contido' => true,
            'transportadora' => [...$j['logo_pequeno'], self::LOGO_JADLOG],
            'remetente' => null,
            'itens' => self::ITENS['jadlog'],
        ];
    }

    /**
     * Imagem centrada na área (fração da página), em até 90% da largura e da altura dela.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $area
     */
    private function imagemCentrada(Fpdi $fpdi, string $arquivo, array $area, float $w, float $h): void
    {
        [$x0, $y0, $x1, $y1] = $area;
        [$lw, $lh] = getimagesize($arquivo);
        $largura = ($x1 - $x0) * $w * 0.9;
        $altura = $largura * $lh / $lw;
        if ($altura > ($y1 - $y0) * $h * 0.9) {
            $altura = ($y1 - $y0) * $h * 0.9;
            $largura = $altura * $lw / $lh;
        }
        $fpdi->Image($arquivo, ($x0 + $x1) / 2 * $w - $largura / 2, ($y0 + $y1) / 2 * $h - $altura / 2, $largura, $altura);
    }

    /**
     * Vários itens numa linha só: "SKU: A  Qtd: 1  |  SKU: B  Qtd: 2". O que
     * não cabe na largura vira "+ N itens (ver pedido)".
     *
     * @param  list<array{sku: ?string, quantidade: int}>  $itens
     */
    private function itensNumaLinha(Fpdi $fpdi, array $itens, float $x0, float $x1, float $base): void
    {
        $fpdi->SetFont('Helvetica', '', 14);
        $partes = array_map(function (array $item): string {
            $sku = trim((string) ($item['sku'] ?? '')) ?: 'sem SKU';
            $sku = (string) (@iconv('UTF-8', 'windows-1252//TRANSLIT', $sku) ?: $sku);

            return 'SKU: '.$sku.'  Qtd: '.(int) ($item['quantidade'] ?? 1);
        }, $itens);
        $total = count($partes);
        $texto = '+ '.$total.' itens (ver pedido)';
        for ($n = $total; $n >= 1; $n--) {
            $tentativa = implode('  |  ', array_slice($partes, 0, $n)).($n < $total ? '  |  + '.($total - $n).' itens (ver pedido)' : '');
            if ($fpdi->GetStringWidth($tentativa) <= $x1 - $x0) {
                $texto = $tentativa;
                break;
            }
        }
        $fpdi->Text($x0, $base, $texto);
    }

    /**
     * O que cobrir e onde escrever. Modelo medido (J&T, Correios, Jadlog)
     * usa as posições medidas; qualquer outra transportadora (Loggi no
     * #4198, LATAM...) é lida por OCR (EtiquetaOcr). null = sai como veio.
     *
     * @return array{cobrir: list<array{0: float, 1: float, 2: float, 3: float}>, logo: ?array{0: float, 1: float, 2: float, 3: float}, contido?: bool, transportadora?: ?array{0: float, 1: float, 2: float, 3: float, 4: string}, remetente: ?array{0: float, 1: float, 2: float, 3: float, 4: float, 5: bool}, itens: ?array{0: float, 1: ?float, 2: float, 3: float, 4: float}}|null
     */
    private function layout(string $pdf, ?string $servico, ?int $orderId): ?array
    {
        $modelo = self::modelo($servico);
        if ($modelo === 'jadlog') {
            return self::layoutJadlog();
        }
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

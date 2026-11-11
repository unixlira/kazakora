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
     * Logo da FerraKora para a térmica: o do site é metal e fogo para fundo
     * escuro e some no papel. Este é o mesmo desenho (martelo no F, serra no
     * O) só em preto sobre branco, sem degradê.
     */
    private const LOGO_FERRAKORA = 'images/marca/logo-ferrakora-etiqueta.png';

    /**
     * Área do logo do Melhor Envio por modelo, em fração da página
     * [x0, y0, x1, y1]. J&T: medida na etiqueta do #3978 (770x1084 px, logo
     * em 97-328 x 866-973), com folga para cobrir o logo inteiro.
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public const AREAS = [
        'jet' => [0.10, 0.785, 0.46, 0.91],
    ];

    public static function modelo(?string $servico): ?string
    {
        $s = mb_strtolower((string) $servico);

        return match (true) {
            str_contains($s, 'jet') || str_contains($s, 'j&t') => 'jet',
            default => null,
        };
    }

    /** Logo que vai na etiqueta conforme o site do pedido (SiteAtual). */
    public static function logo(?string $site): string
    {
        return $site === \App\Support\SiteAtual::FERRAKORA ? self::LOGO_FERRAKORA : self::LOGO;
    }

    /**
     * @param  ?string  $site  site do pedido (SiteAtual): FerraKora sai com o
     *                         logo dela em preto e branco; o resto, KazaKora.
     */
    public function aplicar(string $pdf, ?string $servico, ?int $orderId = null, ?string $site = null): string
    {
        $modelo = self::modelo($servico);
        if (! $modelo || ! isset(self::AREAS[$modelo])) {
            Log::channel(config('melhorenvio.log_channel'))->info('melhorenvio.logo_sem_posicao', ['order_id' => $orderId, 'servico' => $servico]);

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
                    [$x0, $y0, $x1, $y1] = self::AREAS[$modelo];
                    $fpdi->SetFillColor(255, 255, 255);
                    $fpdi->Rect($x0 * $w, $y0 * $h, ($x1 - $x0) * $w, ($y1 - $y0) * $h, 'F');

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
                    $fpdi->Image(
                        $logo,
                        ($x0 + $x1) / 2 * $w - $largura / 2,
                        ($y0 + $y1) / 2 * $h - $altura / 2,
                        $largura,
                        $altura,
                    );
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
}

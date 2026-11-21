<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Leitura da etiqueta por OCR (pedido do Lira em 15/11/2026, #4198): a
 * etiqueta do Melhor Envio é uma imagem só e cada transportadora põe o logo
 * num lugar. A da Loggi saiu com o logo do Melhor Envio porque só J&T e
 * Correios tinham posição medida. Com o OCR o sistema acha o logo, o
 * remetente e o espaço livre em qualquer modelo, e confere o resultado antes
 * de imprimir.
 *
 * Ghostscript desenha a 1ª página em PNG e o tesseract devolve cada palavra
 * com a caixa dela. As posições saem em fração da página (0 a 1).
 */
class EtiquetaOcr
{
    private const DPI = 150;

    /** Palavra que é marca do Melhor Envio ("melhor", "melhorenvio.com"). */
    public const MARCA = '/melhor/i';

    /** "envio" sozinho só é marca quando está colado num "melhor" (o logo é escrito em duas linhas). */
    private const ENVIO = '/^envio[)\\]}]*$/i';

    /**
     * Palavras da 1ª página. null quando o OCR não está disponível ou falhou
     * (quem chama decide; a etiqueta da loja não imprime sem a conferência).
     *
     * @return list<array{texto: string, x0: float, y0: float, x1: float, y1: float, linha: string}>|null
     */
    public function palavras(string $pdf): ?array
    {
        $base = tempnam(sys_get_temp_dir(), 'etq_ocr_');
        $entrada = $base.'.pdf';
        $imagem = $base.'.png';
        file_put_contents($entrada, $pdf);

        try {
            $gs = Process::timeout(30)->run([
                'gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=pnggray', '-r'.self::DPI,
                '-dFirstPage=1', '-dLastPage=1', '-sOutputFile='.$imagem, $entrada,
            ]);
            if (! $gs->successful() || ! is_file($imagem)) {
                Log::channel(config('melhorenvio.log_channel'))->warning('etiqueta.ocr_render_falhou', ['erro' => mb_substr($gs->errorOutput(), 0, 200)]);

                return null;
            }

            [$largura, $altura] = getimagesize($imagem) ?: [0, 0];
            if ($largura < 1 || $altura < 1) {
                return null;
            }

            // #4356 (21/11/2026): várias leituras ao mesmo tempo, cada uma com
            // 4 threads do OpenMP nos 4 núcleos da VPS, travaram o tesseract
            // por mais de 60 s (sozinho leva 0,5 s). Uma thread por leitura e
            // até 3 tentativas antes de desistir.
            $ocr = null;
            for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
                try {
                    $ocr = Process::timeout(90)->env(['OMP_THREAD_LIMIT' => '1'])
                        ->run(['tesseract', $imagem, '-', '--psm', '11', 'tsv']);
                    if ($ocr->successful()) {
                        break;
                    }
                } catch (Throwable $e) {
                    Log::channel(config('melhorenvio.log_channel'))->warning('etiqueta.ocr_erro', ['tentativa' => $tentativa, 'message' => $e->getMessage()]);
                    $ocr = null;
                }
                if ($tentativa < 3) {
                    usleep(500_000 * $tentativa);
                }
            }
            if ($ocr === null) {
                return null;
            }
            if (! $ocr->successful()) {
                Log::channel(config('melhorenvio.log_channel'))->warning('etiqueta.ocr_falhou', ['erro' => mb_substr($ocr->errorOutput(), 0, 200)]);

                return null;
            }

            $palavras = [];
            foreach (preg_split('/\R/', trim($ocr->output())) as $i => $linha) {
                $c = explode("\t", $linha);
                if ($i === 0 || count($c) < 12) {
                    continue;
                }
                $texto = trim($c[11]);
                if ($texto === '' || (float) $c[10] < 30) {
                    continue;
                }
                [$x, $y, $w, $h] = [(int) $c[6], (int) $c[7], (int) $c[8], (int) $c[9]];
                $palavras[] = [
                    'texto' => $texto,
                    'x0' => $x / $largura,
                    'y0' => $y / $altura,
                    'x1' => ($x + $w) / $largura,
                    'y1' => ($y + $h) / $altura,
                    'linha' => $c[2].'-'.$c[3].'-'.$c[4],
                ];
            }

            return $palavras;
        } catch (Throwable $e) {
            Log::channel(config('melhorenvio.log_channel'))->warning('etiqueta.ocr_erro', ['message' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($base);
            @unlink($entrada);
            @unlink($imagem);
        }
    }

    /**
     * true = o OCR ainda acha a marca do Melhor Envio; false = não acha;
     * null = OCR indisponível (não dá para afirmar nada).
     */
    public function temMarcaDoMelhorEnvio(string $pdf): ?bool
    {
        $palavras = $this->palavras($pdf);

        return $palavras === null ? null : collect($palavras)->contains(fn (array $p) => preg_match(self::MARCA, $p['texto']) === 1);
    }

    /**
     * Onde fica cada coisa numa etiqueta de modelo ainda não medido, a partir
     * das palavras: as caixas da marca (para cobrir), a área do logo (onde
     * "melhor" e "envio" estão juntos), a linha do nome do remetente e o
     * espaço livre embaixo para SKU e quantidade.
     *
     * @param  list<array{texto: string, x0: float, y0: float, x1: float, y1: float, linha: string}>  $palavras
     * @return array{cobrir: list<array{0: float, 1: float, 2: float, 3: float}>, logo: ?array{0: float, 1: float, 2: float, 3: float}, remetente: ?array{0: float, 1: float, 2: float, 3: float, 4: float, 5: bool}, itens: ?array{0: float, 1: float, 2: float, 3: float, 4: float}}
     */
    public static function layout(array $palavras): array
    {
        $todas = collect($palavras);
        $melhor = $todas->filter(fn (array $p) => preg_match(self::MARCA, $p['texto']) === 1);
        $marca = $todas->filter(fn (array $p) => preg_match(self::MARCA, $p['texto']) === 1
            || (preg_match(self::ENVIO, $p['texto']) === 1 && $melhor->contains(fn (array $m) => abs($m['y1'] - $p['y0']) < 0.05 && $m['x0'] < $p['x1'] && $m['x1'] > $p['x0'])))->values();
        $ehMarca = fn (array $o) => $marca->contains(fn (array $m) => $m === $o);

        // Cada palavra da marca é coberta com folga; o logo desenhado do
        // Melhor Envio passa um pouco das letras (o traço em volta de "envio").
        $cobrir = $marca->map(fn (array $p) => self::caixaSemInvadir([
            max(0.0, $p['x0'] - 0.015), max(0.0, $p['y0'] - 0.015), min(1.0, $p['x1'] + 0.02), min(1.0, $p['y1'] + 0.006),
        ], $todas->reject($ehMarca)->all()))->values()->all();

        // Logo: "melhor" + "envio" desenhados juntos (não o endereço .com).
        $doLogo = $marca->reject(fn (array $p) => str_contains(mb_strtolower($p['texto']), '.com'));
        $logo = null;
        if ($doLogo->isNotEmpty()) {
            $logo = self::caixaSemInvadir([
                max(0.0, $doLogo->min('x0') - 0.015), max(0.0, $doLogo->min('y0') - 0.015),
                // O desenho do logo tem um traço abaixo de "envio" e uma ponta à
                // direita: a área desce até a próxima palavra que não é da marca
                // (caixaSemInvadir corta antes dela) e não passa da borda.
                min(0.982, $doLogo->max('x1') + 0.03), min(1.0, $doLogo->max('y1') + 0.035),
            ], $todas->reject($ehMarca)->all());
            $cobrir[] = $logo;
        }

        // Remetente: a linha logo abaixo da palavra REMETENTE (ou a própria
        // linha, quando o modelo escreve "REMETENTE: Nome, Tel").
        $remetente = null;
        $rotulo = $todas->first(fn (array $p) => preg_match('/^REMETENTE:?$/i', $p['texto']) === 1);
        if ($rotulo) {
            $mesmaLinha = $todas->filter(fn (array $p) => $p['linha'] === $rotulo['linha'] && $p['x0'] > $rotulo['x1']);
            if ($mesmaLinha->isNotEmpty()) {
                $remetente = [0.03, $rotulo['y0'] - 0.004, 0.97, $rotulo['y1'] + 0.004, $rotulo['y1'] - 0.002, true];
            } else {
                $proxima = $todas->filter(fn (array $p) => $p['y0'] > $rotulo['y1'] && $p['y0'] < $rotulo['y1'] + 0.04)->sortBy('y0')->values();
                if ($proxima->isNotEmpty()) {
                    $linha = $proxima->filter(fn (array $p) => $p['linha'] === $proxima[0]['linha']);
                    $remetente = [0.03, $linha->min('y0') - 0.004, 0.97, $linha->max('y1') + 0.004, $linha->max('y1') - 0.002, false];
                }
            }
        }

        // SKU e quantidade no espaço livre abaixo do último texto.
        $fim = (float) ($todas->max('y1') ?? 1.0);
        $itens = 0.97 - $fim >= 0.06 ? [0.0365, $fim + 0.02, 0.9635, $fim + 0.047, 0.965] : null;

        return ['cobrir' => $cobrir, 'logo' => $logo, 'remetente' => $remetente, 'itens' => $itens];
    }

    /**
     * Encolhe a caixa para não apagar dado oficial vizinho (peso, NF,
     * rastreio): a folga não pode encostar em palavra que não é da marca.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $caixa
     * @param  array<int, array{x0: float, y0: float, x1: float, y1: float}>  $outras
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function caixaSemInvadir(array $caixa, array $outras): array
    {
        [$x0, $y0, $x1, $y1] = $caixa;
        foreach ($outras as $o) {
            // Risco do desenho lido como "—" ou "~" não é dado: só palavra com letra ou número segura a caixa.
            if (preg_match('/[\p{L}\d]{2,}/u', (string) ($o['texto'] ?? '')) !== 1) {
                continue;
            }
            $cruzaX = $o['x0'] < $x1 && $o['x1'] > $x0;
            $cruzaY = $o['y0'] < $y1 && $o['y1'] > $y0;
            if (! $cruzaX || ! $cruzaY) {
                continue;
            }
            // Palavra abaixo ou acima: corta na vertical; ao lado: na horizontal.
            if ($o['y0'] >= ($y0 + $y1) / 2) {
                $y1 = min($y1, $o['y0'] - 0.002);
            } elseif ($o['y1'] <= ($y0 + $y1) / 2) {
                $y0 = max($y0, $o['y1'] + 0.002);
            } elseif ($o['x0'] >= ($x0 + $x1) / 2) {
                $x1 = min($x1, $o['x0'] - 0.002);
            } else {
                $x0 = max($x0, $o['x1'] + 0.002);
            }
        }

        return [$x0, $y0, $x1, $y1];
    }
}

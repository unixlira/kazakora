<?php

namespace App\Modules\Marketplace\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Meta ADS (2026-10-18): formato do vídeo do anúncio.
 *
 * Padrão tirado da campanha da Árvore de Natal que o Lira montou no
 * Gerenciador: os 3 vídeos são verticais 9:16 (576x1024) e a própria Meta
 * gerou as outras versões (4:5 com as laterais desfocadas, 4:5 e 1:1
 * recortados, 9:16 com faixas desfocadas em cima e embaixo) pelas melhorias
 * video_auto_crop e video_uncrop. Vídeo horizontal (16:9) não entra em
 * Stories e Reels do jeito que veio, então vira 9:16 aqui antes de subir:
 * o vídeo inteiro no meio e ele mesmo ampliado e desfocado preenchendo o
 * resto, o mesmo visual que a Meta usa nas versões dela.
 */
class FormatoDeVideo
{
    public const LARGURA_VERTICAL = 1080;

    public const ALTURA_VERTICAL = 1920;

    /**
     * Largura e altura como o vídeo aparece na tela (já girado quando o
     * celular gravou em pé com rotação nos metadados). Null quando o
     * ffprobe não existe ou não entende o arquivo.
     *
     * @return array{largura: int, altura: int}|null
     */
    public function medir(string $caminho): ?array
    {
        try {
            $processo = new Process([
                // -show_streams inteiro: o nome da seção de side data muda
                // entre versões do ffprobe (4.4 na VPS recusa stream_side_data).
                $this->binario('ffprobe'), '-v', 'error', '-select_streams', 'v:0',
                '-show_streams', '-of', 'json', $caminho,
            ]);
            $processo->setTimeout(20)->run();
        } catch (Throwable) {
            return null;
        }

        $stream = json_decode($processo->getOutput(), true)['streams'][0] ?? null;
        if (! $processo->isSuccessful() || ! is_array($stream) || empty($stream['width']) || empty($stream['height'])) {
            return null;
        }

        $rotacao = (int) ($stream['tags']['rotate'] ?? collect($stream['side_data_list'] ?? [])->pluck('rotation')->filter()->first() ?? 0);
        [$largura, $altura] = abs($rotacao) % 180 === 90
            ? [(int) $stream['height'], (int) $stream['width']]
            : [(int) $stream['width'], (int) $stream['height']];

        return ['largura' => $largura, 'altura' => $altura];
    }

    /** Horizontal (16:9, 4:3...) precisa virar 9:16; vertical e quadrado a Meta adapta sozinha. */
    public static function precisaFicarVertical(?int $largura, ?int $altura): bool
    {
        return $largura !== null && $altura !== null && $largura > $altura;
    }

    /** "16:9 horizontal (1920x1080)", para a tela. */
    public static function descrever(?int $largura, ?int $altura): ?string
    {
        if (! $largura || ! $altura) {
            return null;
        }

        $proporcao = $largura / $altura;
        $conhecidas = ['9:16' => 9 / 16, '4:5' => 4 / 5, '1:1' => 1.0, '4:3' => 4 / 3, '16:9' => 16 / 9];
        $nome = collect($conhecidas)->filter(fn (float $p) => abs($p - $proporcao) / $p < 0.03)->keys()->first();
        $tipo = match (true) {
            $largura > $altura => 'horizontal',
            $largura < $altura => 'vertical',
            default => 'quadrado',
        };

        return trim(($nome ?? '').' '.$tipo)." ({$largura}x{$altura})";
    }

    /**
     * Gera a versão 9:16 (1080x1920) com laterais desfocadas: H.264,
     * yuv420p, AAC e moov no começo, que a Meta processa sem reclamar.
     *
     * @throws \RuntimeException quando o ffmpeg falha ou não existe
     */
    public function deixarVertical(string $origem, string $destino): void
    {
        $l = self::LARGURA_VERTICAL;
        $a = self::ALTURA_VERTICAL;
        $filtro = '[0:v]split=2[fundo][frente];'
            ."[fundo]scale={$l}:{$a}:force_original_aspect_ratio=increase,crop={$l}:{$a},boxblur=30:3,eq=brightness=-0.08[f];"
            ."[frente]scale={$l}:{$a}:force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2[v];"
            .'[f][v]overlay=(W-w)/2:(H-h)/2,setsar=1,format=yuv420p[saida]';

        $processo = new Process([
            $this->binario('ffmpeg'), '-y', '-loglevel', 'error', '-i', $origem,
            '-filter_complex', $filtro, '-map', '[saida]', '-map', '0:a?',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $destino,
        ]);
        $processo->setTimeout(240)->run();

        if (! $processo->isSuccessful() || ! is_file($destino) || filesize($destino) === 0) {
            @unlink($destino);

            throw new \RuntimeException('ffmpeg não deixou o vídeo vertical: '.mb_substr(trim($processo->getErrorOutput()), -300));
        }
    }

    /**
     * Na VPS os binários estão no PATH; na hospedagem compartilhada
     * (homolog) ficam em ~/bin. FFMPEG_BIN / services.ffprobe.path têm prioridade.
     */
    private function binario(string $nome): string
    {
        $configurado = $nome === 'ffprobe' ? (string) config('services.ffprobe.path') : (string) env('FFMPEG_BIN', '');
        $naHome = rtrim((string) (getenv('HOME') ?: ''), '/').'/bin/'.$nome;

        return $configurado !== '' ? $configurado : (is_executable($naHome) ? $naHome : $nome);
    }
}

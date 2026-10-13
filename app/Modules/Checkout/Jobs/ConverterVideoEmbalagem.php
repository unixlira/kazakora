<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\OrderPackingVideo;
use App\Modules\Marketplace\Support\DuracaoDeVideo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Vídeo da embalagem (2026-10-11): o navegador grava WebM; aqui vira MP4
 * H.264 compacto (720p, CRF 30, sem áudio, faststart para tocar no
 * navegador enquanto baixa) e o original some.
 *
 * 30 quadros/s fixos (Lira não conseguia ver os vídeos, 2026-10-13): o WebM
 * do navegador não traz taxa de quadros e o ffmpeg usava a base de tempo
 * (1000/s), duplicando quadros. Saía MP4 de 1000 quadros/s, nível H.264 6.0,
 * que o navegador não toca, e vídeo maior estourava o tempo da conversão
 * (ficava em "processando"). `$refazer` reconverte um MP4 já pronto
 * (comando embalagem:reconverter).
 */
class ConverterVideoEmbalagem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 100;

    public const QUADROS_POR_SEGUNDO = 30;

    public function __construct(public readonly int $videoId, public readonly bool $refazer = false)
    {
    }

    public function handle(DuracaoDeVideo $duracao): void
    {
        $video = OrderPackingVideo::query()->find($this->videoId);
        if (! $video || ($video->status === 'pronto' && ! $this->refazer)) {
            return;
        }

        $disco = Storage::disk('local');
        $origem = $disco->path($video->path);
        $destinoRelativo = preg_replace('/\.[^.]+$/', '', $video->path).'.mp4';
        // Refazendo um MP4, o ffmpeg não pode escrever no próprio arquivo de entrada.
        $mesmoArquivo = $destinoRelativo === $video->path;
        $destino = $disco->path($destinoRelativo).($mesmoArquivo ? '.novo.mp4' : '');

        $processo = new Process([
            (string) config('video-downloads.ffmpeg_binary', '/usr/bin/ffmpeg'), '-y', '-i', $origem,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '30', '-vf', 'scale=-2:min(720\,ih)',
            '-r', (string) self::QUADROS_POR_SEGUNDO, '-pix_fmt', 'yuv420p', '-an', '-movflags', '+faststart', $destino,
        ]);
        $processo->setTimeout(95);
        $processo->run();

        if (! $processo->isSuccessful() || ! is_file($destino) || filesize($destino) === 0) {
            if ($mesmoArquivo) {
                // Refazer que falhou: o MP4 antigo continua como estava.
                @unlink($destino);
                $video->update(['erro' => mb_substr('Não reconverteu: '.$processo->getErrorOutput(), 0, 300)]);

                return;
            }
            // Sem conversão, o original continua servindo de prova.
            $video->update(['status' => 'pronto', 'tamanho' => (int) @filesize($origem), 'erro' => mb_substr('Não converteu para MP4: '.$processo->getErrorOutput(), 0, 300)]);

            return;
        }

        if ($mesmoArquivo) {
            rename($destino, $origem);
            $destino = $origem;
        } else {
            $disco->delete($video->path);
        }

        $video->update([
            'path' => $destinoRelativo,
            'status' => 'pronto',
            'tamanho' => filesize($destino),
            'duracao_segundos' => ($s = $duracao->segundos($destino)) !== null ? (int) round($s) : null,
            'erro' => null,
        ]);
    }
}

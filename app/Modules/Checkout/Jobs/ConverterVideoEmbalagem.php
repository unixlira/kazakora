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
 */
class ConverterVideoEmbalagem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 100;

    public function __construct(public readonly int $videoId)
    {
    }

    public function handle(DuracaoDeVideo $duracao): void
    {
        $video = OrderPackingVideo::query()->find($this->videoId);
        if (! $video || $video->status === 'pronto') {
            return;
        }

        $disco = Storage::disk('local');
        $origem = $disco->path($video->path);
        $destinoRelativo = preg_replace('/\.[^.]+$/', '', $video->path).'.mp4';
        $destino = $disco->path($destinoRelativo);

        $processo = new Process([
            (string) config('video-downloads.ffmpeg_binary', '/usr/bin/ffmpeg'), '-y', '-i', $origem,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '30', '-vf', 'scale=-2:min(720\,ih)',
            '-pix_fmt', 'yuv420p', '-an', '-movflags', '+faststart', $destino,
        ]);
        $processo->setTimeout(95);
        $processo->run();

        if (! $processo->isSuccessful() || ! is_file($destino) || filesize($destino) === 0) {
            // Sem conversão, o original continua servindo de prova.
            $video->update(['status' => 'pronto', 'tamanho' => (int) @filesize($origem), 'erro' => mb_substr('Não converteu para MP4: '.$processo->getErrorOutput(), 0, 300)]);

            return;
        }

        if ($destinoRelativo !== $video->path) {
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

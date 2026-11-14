<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Support\Ffmpeg;
use RuntimeException;

/**
 * Áudio gravado no navegador (Chrome grava webm/opus, Safari mp4/aac) para
 * ogg/opus, o único formato que o WhatsApp mostra como nota de voz.
 * Devolve o caminho de um arquivo temporário; quem chama apaga depois.
 *
 * Formato testado com o Lira em 14/11/2026: em 48 kHz e 32 kbps o áudio
 * chegava ao cliente mas não baixava ("erro ao baixar"), mesmo com o arquivo
 * íntegro na Meta. O que baixa e toca é mono, 16 kHz, 24 kbps, quadros de
 * 20 ms, enviado com audio.voice = true (WhatsAppCloudApiClient::sendMedia).
 * Em m4a e mp3 também chega, mas como arquivo de áudio, não como nota de voz.
 */
class ConversorDeVoz
{
    public function paraOgg(string $origem): string
    {
        $base = tempnam(sys_get_temp_dir(), 'wa-voz-');
        $saida = $base.'.ogg';

        $comando = sprintf(
            '%s -y -loglevel error -i %s -vn -ac 1 -ar 16000 -c:a libopus -b:a 24k -application voip -frame_duration 20 -vbr on %s 2>&1',
            escapeshellcmd(Ffmpeg::binario()),
            escapeshellarg($origem),
            escapeshellarg($saida),
        );
        exec($comando, $linhas, $codigo);
        @unlink($base);

        if ($codigo !== 0 || ! is_file($saida) || filesize($saida) === 0) {
            @unlink($saida);

            throw new RuntimeException('ffmpeg não converteu o áudio gravado: '.implode(' ', array_slice($linhas, -3)));
        }

        return $saida;
    }
}

<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Support\Ffmpeg;
use RuntimeException;

/**
 * Áudio gravado no navegador (Chrome grava webm/opus, Safari mp4/aac) para
 * ogg/opus, o único formato que o WhatsApp mostra como nota de voz.
 * Devolve o caminho de um arquivo temporário; quem chama apaga depois.
 */
class ConversorDeVoz
{
    public function paraOgg(string $origem): string
    {
        $base = tempnam(sys_get_temp_dir(), 'wa-voz-');
        $saida = $base.'.ogg';

        $comando = sprintf(
            '%s -y -loglevel error -i %s -vn -ac 1 -ar 48000 -c:a libopus -b:a 32k -application voip %s 2>&1',
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

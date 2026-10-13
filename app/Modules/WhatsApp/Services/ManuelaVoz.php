<?php

namespace App\Modules\WhatsApp\Services;

use RuntimeException;

/**
 * Resposta falada da Manuela: texto → voz (Gemini TTS) → ogg/opus, o formato
 * que o WhatsApp mostra como nota de voz. Devolve o caminho de um arquivo
 * temporário; quem chama apaga depois do envio.
 */
class ManuelaVoz
{
    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function gerar(string $texto): string
    {
        $pcm = $this->gemini->speak($texto);

        $base = tempnam(sys_get_temp_dir(), 'manuela-voz-');
        $entrada = $base.'.pcm';
        $saida = $base.'.ogg';
        file_put_contents($entrada, $pcm);

        $comando = sprintf(
            'ffmpeg -y -loglevel error -f s16le -ar 24000 -ac 1 -i %s -c:a libopus -b:a 32k -application voip %s 2>&1',
            escapeshellarg($entrada),
            escapeshellarg($saida),
        );
        exec($comando, $linhas, $codigo);
        @unlink($entrada);
        @unlink($base);

        if ($codigo !== 0 || ! is_file($saida) || filesize($saida) === 0) {
            @unlink($saida);

            throw new RuntimeException('ffmpeg não converteu a voz da Manuela: '.implode(' ', array_slice($linhas, -3)));
        }

        return $saida;
    }
}

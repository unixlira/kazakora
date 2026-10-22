<?php

namespace App\Modules\WhatsApp\Support;

class Ffmpeg
{
    /**
     * Na VPS o ffmpeg está no PATH; na hospedagem compartilhada (homolog) ele
     * fica em ~/bin, fora do PATH do PHP. FFMPEG_BIN no .env tem prioridade.
     */
    public static function binario(): string
    {
        $configurado = (string) env('FFMPEG_BIN', '');
        $emHome = rtrim((string) getenv('HOME'), '/').'/bin/ffmpeg';

        return $configurado !== '' ? $configurado : (is_executable($emHome) ? $emHome : 'ffmpeg');
    }
}

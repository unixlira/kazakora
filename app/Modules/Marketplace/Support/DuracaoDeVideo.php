<?php

namespace App\Modules\Marketplace\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Duração real de um vídeo, lida pelo ffprobe no servidor — o navegador
 * também confere antes de enviar, mas aquilo é só conforto, não garante
 * nada. Null quando o ffprobe não existe ou não entende o arquivo; quem
 * chama decide o que fazer (ver DevolucoesController::anexarEvidencia()).
 */
class DuracaoDeVideo
{
    public function segundos(string $caminho): ?float
    {
        try {
            $processo = new Process([$this->ffprobe(), '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $caminho]);
            $processo->setTimeout(20)->run();
        } catch (Throwable) {
            return null;
        }

        $saida = trim($processo->getOutput());

        return $processo->isSuccessful() && is_numeric($saida) ? (float) $saida : null;
    }

    private function ffprobe(): string
    {
        if ($configurado = config('services.ffprobe.path')) {
            return $configurado;
        }

        // Hospedagem compartilhada: o binário mora na home, fora do PATH do PHP.
        $naHome = rtrim((string) (getenv('HOME') ?: ''), '/').'/bin/ffprobe';

        return is_executable($naHome) ? $naHome : 'ffprobe';
    }
}

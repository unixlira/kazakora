<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\OrderPackingVideo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Apaga os vídeos de embalagem com mais de 90 dias (2026-10-11). */
class LimparVideosEmbalagem extends Command
{
    protected $signature = 'videos:limpar-embalagem';

    protected $description = 'Apaga os vídeos de embalagem vencidos (90 dias)';

    public function handle(): int
    {
        $apagados = 0;

        OrderPackingVideo::query()->where('expira_em', '<', now())->each(function (OrderPackingVideo $video) use (&$apagados) {
            Storage::disk('local')->delete($video->path);
            $video->delete();
            $apagados++;
        });

        $this->info("{$apagados} vídeo(s) apagado(s).");

        return self::SUCCESS;
    }
}

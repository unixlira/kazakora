<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Jobs\ConverterVideoEmbalagem;
use App\Modules\Checkout\Models\OrderPackingVideo;
use Illuminate\Console\Command;

/**
 * Reconverte vídeos da embalagem para MP4 de 30 quadros/s (Lira não
 * conseguia ver os vídeos, 2026-10-13; ver ConverterVideoEmbalagem). Sem
 * --executar só lista o que faria. O arquivo antigo só é trocado quando a
 * conversão nova dá certo.
 */
class ReconverterVideosEmbalagem extends Command
{
    protected $signature = 'embalagem:reconverter {ids?* : IDs dos vídeos (vazio = todos)} {--executar : Reconverte de fato (sem isso só lista)}';

    protected $description = 'Reconverte os vídeos da embalagem para MP4 de 30 quadros/s';

    public function handle(): int
    {
        $videos = OrderPackingVideo::query()
            ->when($this->argument('ids'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        foreach ($videos as $video) {
            $this->line("#{$video->id} pedido {$video->order_id} {$video->status} {$video->path}");
            if ($this->option('executar')) {
                ConverterVideoEmbalagem::dispatch($video->id, true);
            }
        }

        $this->info($this->option('executar')
            ? "{$videos->count()} vídeo(s) na fila para reconverter."
            : "{$videos->count()} vídeo(s). Nada feito: use --executar.");

        return self::SUCCESS;
    }
}

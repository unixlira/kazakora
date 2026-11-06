<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\ReviewImage;
use Illuminate\Console\Command;

/**
 * Miniaturas das fotos de avaliação que já estavam na loja antes de 06/11
 * (tarefa 3878); foto nova já nasce com a dela (ReviewImage::booted()).
 *
 * Só olha linha sem miniatura e anda pelo id, então pode rodar de hora em
 * hora até zerar. Vídeo e foto que não deu para reduzir ficam com texto vazio
 * na coluna, para não serem tentados de novo (a tela usa a original).
 */
class GerarMiniaturasAvaliacoes extends Command
{
    protected $signature = 'avaliacoes:miniaturas {--limite=2000 : Teto de fotos nesta execução}';

    protected $description = 'Gera a miniatura das fotos de avaliação guardadas na loja';

    public function handle(): int
    {
        $pendentes = ReviewImage::query()
            ->whereNull('thumb_url')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limite')))
            ->get(['id', 'image_url', 'thumb_url']);

        $geradas = 0;
        $semMiniatura = 0;

        foreach ($pendentes as $imagem) {
            $miniatura = ReviewImage::gerarMiniatura($imagem->image_url);
            $miniatura ? $geradas++ : $semMiniatura++;

            $imagem->thumb_url = $miniatura ?? '';
            $imagem->saveQuietly();
        }

        $this->info("Miniaturas geradas: {$geradas}. Sem miniatura (vídeo, fora da loja ou falha): {$semMiniatura}.");

        return self::SUCCESS;
    }
}

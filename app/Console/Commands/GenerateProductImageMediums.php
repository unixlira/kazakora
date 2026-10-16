<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\ProductImageThumbnailer;
use Illuminate\Console\Command;

/**
 * Gera a foto média (1200px) da página de produto para as fotos cadastradas
 * antes de 2026-10-15; foto nova já nasce com a dela (ProductImage::booted()).
 * Idempotente como o catalog:generate-thumbnails: só olha medium_path nulo.
 * NÃO toca no arquivo original: só escreve arquivo novo em `<pasta>/medias/`.
 */
class GenerateProductImageMediums extends Command
{
    protected $signature = 'catalog:generate-medium-images {--limit=1000 : Teto de fotos processadas nesta execução}';

    protected $description = 'Gera a foto média da página de produto para fotos que ainda não têm';

    public function handle(): int
    {
        $pending = ProductImage::query()
            ->whereNull('medium_path')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nenhuma foto sem a versão média.');

            return self::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($pending as $image) {
            $mediumPath = ProductImageThumbnailer::generateMedium($image->path);

            if (! $mediumPath) {
                $skipped++;
                $this->warn("Não foi possível gerar a foto média de #{$image->id} ({$image->path}).");

                continue;
            }

            // saveQuietly: não é uma edição de catálogo que precise ir pro
            // audit log nem disparar observer — é derivado do que já existe.
            $image->medium_path = $mediumPath;
            $image->saveQuietly();

            $generated++;
        }

        $this->info("Fotos médias geradas: {$generated}. Sem gerar: {$skipped}.");

        return self::SUCCESS;
    }
}

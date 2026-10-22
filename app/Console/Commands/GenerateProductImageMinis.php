<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\ProductImageThumbnailer;
use Illuminate\Console\Command;

/**
 * Gera a foto pequena (320px) das miniaturas para as fotos cadastradas antes
 * de 2026-10-21; foto nova já nasce com a dela (ProductImage::booted()).
 * Idempotente como o catalog:generate-thumbnails: só olha mini_path nulo.
 * NÃO toca no arquivo original: só escreve arquivo novo em `<pasta>/minis/`.
 */
class GenerateProductImageMinis extends Command
{
    protected $signature = 'catalog:generate-mini-images {--limit=1000 : Teto de fotos processadas nesta execução}';

    protected $description = 'Gera a foto pequena das miniaturas para fotos que ainda não têm';

    public function handle(): int
    {
        $pending = ProductImage::query()
            ->whereNull('mini_path')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nenhuma foto sem a versão pequena.');

            return self::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($pending as $image) {
            $miniPath = ProductImageThumbnailer::generateMini($image->path);

            if (! $miniPath) {
                $skipped++;
                $this->warn("Não foi possível gerar a foto pequena de #{$image->id} ({$image->path}).");

                continue;
            }

            // saveQuietly: não é uma edição de catálogo que precise ir pro
            // audit log nem disparar observer — é derivado do que já existe.
            $image->mini_path = $miniPath;
            $image->saveQuietly();

            $generated++;
        }

        $this->info("Fotos pequenas geradas: {$generated}. Sem gerar: {$skipped}.");

        return self::SUCCESS;
    }
}

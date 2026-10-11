<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\ProductImageThumbnailer;
use Illuminate\Console\Command;

/**
 * Gera a miniatura de vitrine das fotos que já estavam cadastradas antes de
 * 2026-09-03 — foto nova já nasce com a dela (ver ProductImage::booted()).
 *
 * Idempotente de propósito: só olha linha com thumb_path nulo, então rodar
 * duas vezes não refaz nada e não custa nada. É por isso que ele pode ficar
 * no post-deploy sem virar peso permanente: depois do primeiro deploy que
 * ele roda, sobra uma consulta que não devolve linha nenhuma.
 *
 * NÃO toca no arquivo original: a original continua sendo o que o zoom da
 * página de produto usa e o que já foi publicado no Mercado Livre e na
 * Shopee. Aqui só se escreve arquivo novo em `<pasta>/thumbs/`.
 */
class GenerateProductImageThumbnails extends Command
{
    protected $signature = 'catalog:generate-thumbnails {--limit=1000 : Teto de fotos processadas nesta execução}';

    protected $description = 'Gera miniaturas de vitrine para fotos de produto que ainda não têm';

    public function handle(): int
    {
        $pending = ProductImage::query()
            ->whereNull('thumb_path')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nenhuma foto sem miniatura.');

            return self::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($pending as $image) {
            $thumbPath = ProductImageThumbnailer::generate($image->path);

            if (! $thumbPath) {
                $skipped++;
                $this->warn("Não foi possível gerar miniatura de #{$image->id} ({$image->path}).");

                continue;
            }

            // saveQuietly: não é uma edição de catálogo que precise ir pro
            // audit log nem disparar observer — é derivado do que já existe.
            $image->thumb_path = $thumbPath;
            $image->saveQuietly();

            $generated++;
        }

        $this->info("Miniaturas geradas: {$generated}. Sem gerar: {$skipped}.");

        return self::SUCCESS;
    }
}

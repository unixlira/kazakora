<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\ProductImageThumbnailer;
use Illuminate\Console\Command;

/**
 * Gera a foto de 800px do celular e a miniatura de 160px da galeria para as
 * fotos cadastradas antes de 2026-11-06 (tarefa 3886); foto nova já nasce
 * com as dela (ProductImage::booted()). `--produto` limita aos produtos
 * escolhidos (o Lira pediu 4 primeiro e o catálogo depois de validar).
 * Idempotente: só olha celular_path ou micro_path nulo.
 * NÃO toca no arquivo original: só escreve arquivo novo em `<pasta>/celular/`
 * e `<pasta>/micros/`.
 */
class GenerateProductImageCelular extends Command
{
    protected $signature = 'catalog:generate-celular-images
        {--produto=* : Só as fotos destes produtos (id); sem a opção, o catálogo todo}
        {--limit=1000 : Teto de fotos processadas nesta execução}';

    protected $description = 'Gera a foto de 800px do celular e a miniatura de 160px da galeria para fotos que ainda não têm';

    public function handle(): int
    {
        $produtos = array_filter(array_map('intval', (array) $this->option('produto')));

        $pending = ProductImage::query()
            ->where(fn ($q) => $q->whereNull('celular_path')->orWhereNull('micro_path'))
            ->when($produtos !== [], fn ($q) => $q->whereIn('product_id', $produtos))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nenhuma foto sem a versão do celular.');

            return self::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($pending as $image) {
            $image->celular_path ??= ProductImageThumbnailer::generateCelular($image->path);
            $image->micro_path ??= ProductImageThumbnailer::generateMicro($image->path);

            if (! $image->celular_path || ! $image->micro_path) {
                $skipped++;
                $this->warn("Não foi possível gerar as versões do celular de #{$image->id} ({$image->path}).");
            }

            if ($image->isDirty()) {
                // saveQuietly: derivado do que já existe, sem audit log nem observer.
                $image->saveQuietly();
                $generated++;
            }
        }

        $this->info("Fotos com versão do celular: {$generated}. Sem gerar: {$skipped}.");

        return self::SUCCESS;
    }
}

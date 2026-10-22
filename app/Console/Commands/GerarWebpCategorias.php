<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Support\BannerMobileOptimizer;
use Illuminate\Console\Command;

/**
 * Desempenho (PageSpeed, pedido 2026-10-21): gera o WebP das imagens das
 * categorias (círculos da home e do menu, até 180 px na tela) ao lado do
 * original, com no máximo 480 px. Não apaga nada e não mexe no banco; o
 * Category::image_url passa a usar o WebP sozinho. Pode rodar de novo.
 */
class GerarWebpCategorias extends Command
{
    protected $signature = 'categorias:gerar-webp';

    protected $description = 'Gera WebP (até 480 px) ao lado das imagens das categorias, sem apagar o original';

    public function handle(): int
    {
        foreach (Category::query()->whereNotNull('image_path')->orderBy('id')->get() as $categoria) {
            $webp = BannerMobileOptimizer::otimizar($categoria->image_path, 'public', [480], manterOriginal: true);
            $webp
                ? $this->info("Categoria {$categoria->id} ({$categoria->slug}): {$webp}")
                : $this->warn("Categoria {$categoria->id} ({$categoria->slug}): não deu para gerar o WebP, fica o original.");
        }

        return self::SUCCESS;
    }
}

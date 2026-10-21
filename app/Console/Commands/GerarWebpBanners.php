<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Support\BannerMobileOptimizer;
use Illuminate\Console\Command;

/**
 * Desempenho (pedido 2026-10-21): gera o WebP dos banners que já estão no ar,
 * ao lado do arquivo original. Não apaga nada e não mexe no banco; o site
 * passa a usar o WebP sozinho. Pode rodar de novo (refaz os WebP).
 */
class GerarWebpBanners extends Command
{
    protected $signature = 'banners:gerar-webp';

    protected $description = 'Gera WebP (computador 1280/1920/2560, celular 480/828/1080) ao lado das imagens dos banners, sem apagar o original';

    public function handle(): int
    {
        foreach (Banner::query()->orderBy('sort_order')->get() as $banner) {
            $imagens = [
                'computador' => [$banner->image_path, BannerMobileOptimizer::LARGURAS_PC],
                'celular' => [$banner->image_path_mobile, BannerMobileOptimizer::LARGURAS],
            ];
            foreach ($imagens as $tela => [$caminho, $larguras]) {
                if (! $caminho) {
                    continue;
                }
                $webp = BannerMobileOptimizer::otimizar($caminho, 'public', $larguras, manterOriginal: true);
                $webp
                    ? $this->info("Banner {$banner->id} ({$tela}): {$webp}")
                    : $this->warn("Banner {$banner->id} ({$tela}): não deu para gerar o WebP, fica o original.");
            }
        }

        return self::SUCCESS;
    }
}

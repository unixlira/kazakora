<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Banner;
use App\Modules\Catalog\Support\BannerMobileOptimizer;
use Illuminate\Console\Command;

class OtimizarBannersMobile extends Command
{
    protected $signature = 'banners:otimizar-mobile';

    protected $description = 'Converte as imagens mobile do Hero para WebP nas larguras 480/828/1080 (o original sai de cena)';

    public function handle(): int
    {
        foreach (Banner::query()->whereNotNull('image_path_mobile')->get() as $banner) {
            if (str_ends_with($banner->image_path_mobile, '.webp')) {
                continue;
            }
            $novo = BannerMobileOptimizer::otimizar($banner->image_path_mobile);
            if ($novo) {
                $banner->update(['image_path_mobile' => $novo]);
                $this->info("Banner {$banner->id}: {$novo}");
            } else {
                $this->warn("Banner {$banner->id}: não foi possível otimizar, mantido.");
            }
        }

        return self::SUCCESS;
    }
}

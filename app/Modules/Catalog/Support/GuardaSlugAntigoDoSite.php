<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Services\CatalogoOutroSite;
use Illuminate\Database\Eloquent\Model;

/**
 * No registro do produto num site irmão (FerraKoraProduto, BeautyKoraProduto):
 * trocar o título do site ou o slug fixo guarda o slug anterior em
 * outro_site_slugs_antigos (2026-11-18), para o link antigo não dar 404.
 */
trait GuardaSlugAntigoDoSite
{
    abstract public static function catalogo(): CatalogoOutroSite;

    public static function bootGuardaSlugAntigoDoSite(): void
    {
        static::updated(function (Model $registro) {
            if (! $registro->wasChanged(['titulo', 'slug'])) {
                return;
            }

            $catalogo = static::catalogo();
            $antes = (new static)->forceFill([
                'product_id' => $registro->product_id,
                'titulo' => $registro->getOriginal('titulo'),
                'slug' => $registro->getOriginal('slug'),
            ]);

            $catalogo->registrarTroca((int) $registro->product_id, $catalogo->slugPublicado($antes), $catalogo->slugPublicado($registro));
        });
    }
}

<?php

namespace App\Modules\BeautyKora\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\Catalog\Support\GuardaSlugAntigoDoSite;
use App\Modules\BeautyKora\Services\CatalogoBeautyKora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produto do KazaKora publicado no site Beauty Kora — ver CatalogoBeautyKora.
 */
class BeautyKoraProduto extends Model
{
    use GuardaSlugAntigoDoSite;

    /** Clonado (anúncio importado do KazaKora): +5% sobre o preço original (2026-10-15). */
    public const ACRESCIMO_CLONADO = 0.05;

    protected $table = 'beautykora_produtos';

    protected $fillable = [
        'product_id',
        'titulo',
        // Endereço fixo no site (2026-11-18): preenchido, vale ele em vez do título.
        'slug',
        'ordem',
        'ativo',
        'clonado',
    ];

    protected $attributes = [
        'clonado' => true,
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'ordem' => 'integer',
            'ativo' => 'boolean',
            'clonado' => 'boolean',
        ];
    }

    public function acrescimo(): float
    {
        return $this->clonado ? self::ACRESCIMO_CLONADO : 0.0;
    }

    public static function catalogo(): CatalogoOutroSite
    {
        return app(CatalogoBeautyKora::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

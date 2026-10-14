<?php

namespace App\Modules\BeautyKora\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produto do KazaKora publicado no site Beauty Kora — ver CatalogoBeautyKora.
 */
class BeautyKoraProduto extends Model
{
    /** Clonado (anúncio importado do KazaKora): +5% sobre o preço original (2026-10-15). */
    public const ACRESCIMO_CLONADO = 0.05;

    protected $table = 'beautykora_produtos';

    protected $fillable = [
        'product_id',
        'titulo',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

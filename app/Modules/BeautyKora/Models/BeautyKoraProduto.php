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
    protected $table = 'beautykora_produtos';

    protected $fillable = [
        'product_id',
        'titulo',
        'ordem',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'ordem' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

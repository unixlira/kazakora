<?php

namespace App\Modules\FerraKora\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produto do KazaKora publicado no site FerraKora — ver CatalogoFerraKora.
 */
class FerraKoraProduto extends Model
{
    protected $table = 'ferrakora_produtos';

    protected $fillable = [
        'product_id',
        'titulo',
        'categoria',
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

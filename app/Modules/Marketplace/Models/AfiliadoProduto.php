<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produto escolhido para virar anúncio de afiliado (Afiliados › Shopee,
 * 2026-10-26). Ver AfiliadosShopee para o preço e os grupos.
 */
class AfiliadoProduto extends Model
{
    protected $table = 'afiliado_produtos';

    protected $fillable = [
        'canal',
        'product_id',
        'sku_afiliado',
        'custo',
        'preco',
        'external_id',
        'escolhido_por',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'custo' => 'decimal:2',
            'preco' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

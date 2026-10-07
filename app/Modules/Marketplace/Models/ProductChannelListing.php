<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductChannelListing extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'product_id',
        'channel',
        'is_enabled',
        'status',
        'external_id',
        'external_model_id',
        'price',
        'attributes',
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'price' => 'decimal:2',
            'attributes' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Preço de venda NESTE canal — pedido do usuário 2026-10-07: cada canal
     * tem seu preço (comissão e frete mudam de um pra outro, e o preço do
     * site mandado pro Mercado Livre já deu anúncio vendendo no prejuízo).
     * Sem preço próprio, vale o do produto, como sempre foi.
     */
    public function precoDeVenda(Product $product): float
    {
        return $this->price !== null ? (float) $this->price : (float) $product->final_price;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

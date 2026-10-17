<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produto da lista do Lira que entra todo dia na Oferta Relâmpago da
 * Shopee (shopee:relampago-diaria, pedido 2026-10-17).
 */
class ShopeeRelampagoItem extends Model
{
    protected $table = 'shopee_relampago_itens';

    public const MODO_PRECO = 'preco';

    public const MODO_DESCONTO_REAIS = 'desconto_reais';

    public const MODO_DESCONTO_PCT = 'desconto_pct';

    public const MODOS = [self::MODO_PRECO, self::MODO_DESCONTO_REAIS, self::MODO_DESCONTO_PCT];

    protected $fillable = [
        'item_id', 'model_id', 'product_id', 'nome', 'modo', 'valor', 'estoque',
        'limite_por_comprador', 'custo', 'lucro_minimo', 'ativo', 'observacao',
    ];

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'model_id' => 'integer',
            'estoque' => 'integer',
            'limite_por_comprador' => 'integer',
            'valor' => 'decimal:2',
            'custo' => 'decimal:2',
            'lucro_minimo' => 'decimal:2',
            'ativo' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Custo por unidade: o próprio da lista ou o do cadastro. */
    public function custoUnidade(): ?float
    {
        if ($this->custo !== null) {
            return (float) $this->custo;
        }

        $custo = $this->product?->cost_price;

        return $custo !== null && (float) $custo > 0 ? (float) $custo : null;
    }
}

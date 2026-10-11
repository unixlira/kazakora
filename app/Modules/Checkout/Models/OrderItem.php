<?php

namespace App\Modules\Checkout\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    public const TYPE_PRODUCT = 'produto';

    public const TYPE_SERVICE = 'servico';

    protected $fillable = [
        'product_id',
        // Item id/model id do canal (ML/Shopee) — persistido SEMPRE (item
        // mapeado ou não), pra dar pra reprocessar depois sem depender de
        // log. Ver migration add_external_item_id e comando
        // marketplace:relink-unmapped-items.
        'external_item_id',
        'external_model_id',
        'product_name',
        'product_price',
        'quantity',
        'subtotal',
        // Custo manual da linha, só usado quando product_id é nulo (sem
        // produto local pra editar cost_price) — ver migration
        // add_manual_cost_price_to_order_items_table e
        // CashFlowController::updateItemCost().
        'manual_cost_price',
        // Fiscal manual — só usados quando product_id é nulo (item digitado
        // na hora na emissão manual de nota, ver NFeXmlBuilderService).
        'item_type',
        'ncm',
        'cest',
        'cfop',
        'cfop_outros_estados',
        'origem_mercadoria',
        'gtin',
        'unidade_tributavel',
        'icms_situacao_tributaria',
        'pis_situacao_tributaria',
        'pis_aliquota',
        'cofins_situacao_tributaria',
        'cofins_aliquota',
        'percentual_aproximado_tributos',
    ];

    protected function casts(): array
    {
        return [
            'product_price' => 'decimal:2',
            'quantity' => 'integer',
            'subtotal' => 'decimal:2',
            'manual_cost_price' => 'decimal:2',
            'origem_mercadoria' => 'integer',
            'pis_aliquota' => 'decimal:2',
            'cofins_aliquota' => 'decimal:2',
            'percentual_aproximado_tributos' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

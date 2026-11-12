<?php

namespace App\Modules\Operacional\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'quantity',
        'unit_cost',
        // Tela Compras (pedido do Lira em 12/11/2026).
        'description',
        'effective_unit_cost',
        'previous_cost',
        'cost_applied_at',
        'cost_applied_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'effective_unit_cost' => 'decimal:4',
            'previous_cost' => 'decimal:2',
            'cost_applied_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function costAppliedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cost_applied_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

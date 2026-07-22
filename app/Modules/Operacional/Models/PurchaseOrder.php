<?php

namespace App\Modules\Operacional\Models;

use App\Models\User;
use App\Modules\Cadastros\Models\Supplier;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'supplier_id',
        'status',
        'expected_date',
        'notes',
        'created_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function total(): float
    {
        return (float) $this->items->sum(fn (PurchaseOrderItem $item) => $item->quantity * $item->unit_cost);
    }
}

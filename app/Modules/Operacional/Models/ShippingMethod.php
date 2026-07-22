<?php

namespace App\Modules\Operacional\Models;

use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'estimated_days',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'estimated_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

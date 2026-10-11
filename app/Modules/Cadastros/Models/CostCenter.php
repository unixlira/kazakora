<?php

namespace App\Modules\Cadastros\Models;

use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    use Auditable;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}

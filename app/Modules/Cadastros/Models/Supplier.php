<?php

namespace App\Modules\Cadastros\Models;

use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'document',
        'email',
        'phone',
        'city',
        'state',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}

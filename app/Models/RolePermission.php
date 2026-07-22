<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    protected $fillable = ['role', 'permission', 'granted'];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
        ];
    }
}

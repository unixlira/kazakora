<?php

namespace App\Modules\Operacional\Models;

use App\Support\Rbac\Auditable;
use Database\Factories\ShippingMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    use Auditable, HasFactory;

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

    protected static function newFactory(): ShippingMethodFactory
    {
        return ShippingMethodFactory::new();
    }

    /**
     * Frete da loja no checkout v2 (pedido 2026-10-10): sempre "Frete
     * GRÁTIS (1 à 7 dias úteis)", logado ou não, sem escolha de transportadora.
     * Cria a linha se ainda não existir (o servidor não tinha nenhum frete).
     */
    public static function freteGratis(): self
    {
        return static::query()->firstOrCreate(
            ['name' => 'Frete Grátis', 'price' => 0],
            ['estimated_days' => 7, 'is_active' => true],
        );
    }
}

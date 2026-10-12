<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma mineração dos mais vendidos da Central do Vendedor da Shopee
 * (ver ImportarMaisVendidosShopee).
 */
class ShopeeMaisVendidosColeta extends Model
{
    protected $table = 'shopee_mais_vendidos_coletas';

    protected $fillable = ['periodo_inicio', 'periodo_fim', 'total_categorias', 'total_produtos', 'coletado_em'];

    protected function casts(): array
    {
        return [
            'periodo_inicio' => 'date',
            'periodo_fim' => 'date',
            'total_categorias' => 'integer',
            'total_produtos' => 'integer',
            'coletado_em' => 'datetime',
        ];
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(ShopeeMaisVendidosCategoria::class, 'coleta_id');
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(ShopeeMaisVendidosProduto::class, 'coleta_id');
    }

    public static function ultima(): ?self
    {
        return self::query()->latest('coletado_em')->latest('id')->first();
    }
}

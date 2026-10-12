<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma coleta do ranking "Produtos em alta" da Central do Vendedor da Shopee
 * (ver RadarShopee).
 */
class ShopeeRadarColeta extends Model
{
    protected $table = 'shopee_radar_coletas';

    protected $fillable = ['coletado_em', 'total_categorias', 'total_produtos', 'origem'];

    protected function casts(): array
    {
        return [
            'coletado_em' => 'datetime',
            'total_categorias' => 'integer',
            'total_produtos' => 'integer',
        ];
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(ShopeeRadarCategoria::class, 'coleta_id');
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(ShopeeRadarProduto::class, 'coleta_id');
    }
}

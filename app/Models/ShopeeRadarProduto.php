<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Produto (de qualquer loja) no ranking de uma categoria da Shopee. */
class ShopeeRadarProduto extends Model
{
    public $timestamps = false;

    protected $table = 'shopee_radar_produtos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'preco' => 'float',
            'preco_min' => 'float',
            'preco_max' => 'float',
            'unidades_vendidas' => 'integer',
            'posicao' => 'integer',
        ];
    }
}

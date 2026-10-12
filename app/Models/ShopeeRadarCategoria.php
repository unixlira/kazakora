<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Categoria de 3º nível do ranking da Shopee, com as unidades vendidas. */
class ShopeeRadarCategoria extends Model
{
    public $timestamps = false;

    protected $table = 'shopee_radar_categorias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unidades_vendidas' => 'integer', 'posicao' => 'integer'];
    }
}

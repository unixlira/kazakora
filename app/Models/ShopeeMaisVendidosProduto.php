<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Produto de terceiro no ranking de mais vendidos da Shopee numa coleta. */
class ShopeeMaisVendidosProduto extends Model
{
    public $timestamps = false;

    protected $table = 'shopee_mais_vendidos_produtos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'posicao' => 'integer',
            'preco' => 'float',
            'preco_min' => 'float',
            'preco_max' => 'float',
            'unidades_vendidas' => 'integer',
        ];
    }
}

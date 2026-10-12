<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Subcategoria (nível 3) da Shopee com as unidades vendidas numa coleta. */
class ShopeeMaisVendidosCategoria extends Model
{
    public $timestamps = false;

    protected $table = 'shopee_mais_vendidos_categorias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['posicao' => 'integer', 'unidades_vendidas' => 'integer'];
    }
}

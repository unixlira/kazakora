<?php

namespace App\Modules\Infoprodutos\Models;

use Illuminate\Database\Eloquent\Model;

/** Carrinho abandonado da Kiwify (checkout iniciado sem compra); só id, produto e país. */
class InfoprodutoCarrinho extends Model
{
    protected $table = 'infoproduto_carrinhos';

    protected $guarded = [];

    protected $casts = ['criado_em' => 'datetime'];
}

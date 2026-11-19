<?php

namespace App\Modules\Infoprodutos\Models;

use Illuminate\Database\Eloquent\Model;

/** Evento anônimo de uma página de infoproduto (sem IP, sem cookie). */
class InfoprodutoEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'infoproduto_eventos';

    protected $guarded = [];

    protected $casts = ['fbclid' => 'boolean', 'teste' => 'boolean', 'created_at' => 'datetime'];
}

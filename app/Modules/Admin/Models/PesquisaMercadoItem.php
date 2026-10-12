<?php

namespace App\Modules\Admin\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Produto observado numa pesquisa de mercado (concorrente ou nosso). */
class PesquisaMercadoItem extends Model
{
    protected $table = 'pesquisa_mercado_itens';

    protected $fillable = [
        'pesquisa_mercado_id',
        'posicao',
        'produto',
        'link',
        'preco',
        'vendas',
        'avaliacoes',
        'vendedor',
        'product_id',
        'observacoes',
        'nota',
    ];

    protected function casts(): array
    {
        return [
            'preco' => 'float',
            'nota' => 'float',
            'posicao' => 'integer',
        ];
    }

    public function pesquisa(): BelongsTo
    {
        return $this->belongsTo(PesquisaMercado::class, 'pesquisa_mercado_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

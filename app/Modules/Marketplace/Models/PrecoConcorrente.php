<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oferta de concorrente achada na pesquisa da madrugada (Sugestões do dia,
 * 2026-10-20). Fica guardada para dar histórico de preço do concorrente.
 * Fontes: catálogo do Mercado Livre (mesmo produto) e o comparador Zoom
 * (Magalu, Amazon, Americanas, Casas Bahia...).
 */
class PrecoConcorrente extends Model
{
    protected $table = 'precos_concorrentes';

    public const FONTE_MERCADO_LIVRE = 'mercado_livre';

    public const FONTE_ZOOM = 'zoom';

    protected $fillable = ['product_id', 'fonte', 'loja', 'titulo', 'preco', 'link', 'similaridade', 'coletado_em'];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'similaridade' => 'decimal:2',
            'coletado_em' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

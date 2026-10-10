<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conteúdo do anúncio da página do produto — ver a migration
 * create_product_ad_contents_table e AnuncioConteudoService.
 */
class ProductAdContent extends Model
{
    public const FONTE_GEMINI = 'gemini';

    public const FONTE_AUTOMATICO = 'automatico';

    protected $fillable = [
        'product_id',
        'destaques',
        'chamada',
        'blocos',
        'comparativo',
        'beneficios',
        'duvidas',
        'fonte',
        'origem_hash',
        'erro',
        'gerado_em',
    ];

    protected $hidden = ['erro', 'origem_hash'];

    protected function casts(): array
    {
        return [
            'destaques' => 'array',
            'blocos' => 'array',
            'comparativo' => 'array',
            'beneficios' => 'array',
            'duvidas' => 'array',
            'gerado_em' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

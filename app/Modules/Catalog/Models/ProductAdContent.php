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

    /** Gemini sem resposta: o Claude do PC do Lira escreveu (2026-10-25). */
    public const FONTE_CLAUDE = 'claude';

    public const FONTE_AUTOMATICO = 'automatico';

    /** Escrito por uma IA (Gemini ou Claude), não pela regra automática. */
    public function porIa(): bool
    {
        return in_array($this->fonte, [self::FONTE_GEMINI, self::FONTE_CLAUDE], true);
    }

    protected $fillable = [
        'product_id',
        'destaques',
        'chamada',
        'blocos',
        'comparativo',
        'beneficios',
        'duvidas',
        'cta',
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
            'cta' => 'array',
            'gerado_em' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

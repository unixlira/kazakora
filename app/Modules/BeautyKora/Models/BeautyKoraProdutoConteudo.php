<?php

namespace App\Modules\BeautyKora\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conteúdo da página de produto do Beauty Kora (Gemini ou regra automática),
 * pelo id do produto na loja da Hostinger — ver ConteudoProdutoBeautyKora.
 */
class BeautyKoraProdutoConteudo extends Model
{
    protected $table = 'beautykora_produto_conteudos';

    protected $fillable = [
        'produto_id',
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
}

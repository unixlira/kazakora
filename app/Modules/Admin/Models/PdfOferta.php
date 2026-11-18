<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Oferta de infoproduto em PDF vista na Biblioteca de Anúncios da Meta
 * (Marketing > Oportunidades em PDF). Vem do importador
 * `pdf-oportunidades:importar`; não é editada pela tela.
 */
class PdfOferta extends Model
{
    protected $table = 'pdf_ofertas';

    protected $guarded = ['id'];

    protected $casts = [
        'ativa' => 'boolean',
        'paises' => 'array',
        'preco' => 'decimal:2',
        'pontuacao' => 'decimal:2',
        'inicio_mais_antigo' => 'date',
        'coletado_em' => 'datetime',
    ];

    public function anuncios(): HasMany
    {
        return $this->hasMany(PdfOfertaAnuncio::class, 'pdf_oferta_id');
    }
}

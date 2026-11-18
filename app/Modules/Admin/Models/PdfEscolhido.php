<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oferta de PDF clonada do radar (Marketing > Oportunidades em PDF > PDFs
 * Escolhidos). Criada por PdfClonagemService; guarda uma cópia da oferta,
 * dos criativos, o plano de ação e o prompt do entregável.
 */
class PdfEscolhido extends Model
{
    protected $table = 'pdf_escolhidos';

    protected $guarded = ['id'];

    protected $casts = [
        'preco' => 'decimal:2',
        'dados' => 'array',
        'anuncios' => 'array',
        'plano_acao' => 'array',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(PdfOferta::class, 'pdf_oferta_id');
    }
}

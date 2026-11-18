<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um anúncio (criativo) de uma oferta de PDF, com a mídia já baixada. */
class PdfOfertaAnuncio extends Model
{
    protected $table = 'pdf_oferta_anuncios';

    protected $guarded = ['id'];

    protected $casts = [
        'iniciado_em' => 'date',
        'plataformas' => 'array',
        'midias' => 'array',
    ];

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(PdfOferta::class, 'pdf_oferta_id');
    }

    public function linkBiblioteca(): string
    {
        return 'https://www.facebook.com/ads/library/?id='.$this->ad_archive_id;
    }
}

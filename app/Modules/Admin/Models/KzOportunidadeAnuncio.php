<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Criativo veiculado de uma oportunidade KazaKora, com a mídia já baixada. */
class KzOportunidadeAnuncio extends Model
{
    protected $table = 'kz_oportunidade_anuncios';

    protected $guarded = ['id'];

    protected $casts = [
        'iniciado_em' => 'date',
        'plataformas' => 'array',
        'midias' => 'array',
    ];

    public function oportunidade(): BelongsTo
    {
        return $this->belongsTo(KzOportunidade::class, 'kz_oportunidade_id');
    }

    public function linkBiblioteca(): string
    {
        return 'https://www.facebook.com/ads/library/?id='.$this->ad_archive_id;
    }
}

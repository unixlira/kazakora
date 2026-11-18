<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Criativo nosso pedido em Oportunidades › KazaKora (clonar um anúncio ou
 * criar do zero). O código (KZ-AAMMDD-0001) identifica o vídeo em todo lugar:
 * pasta no servidor, nome do arquivo e nome do anúncio na Meta.
 */
class KzCriativo extends Model
{
    public const STATUS = [
        'roteirizando' => 'Gerando roteiro',
        'roteiro_pronto' => 'Roteiro pronto · aguardando produção',
        'produzindo' => 'Produzindo vídeo',
        'pronto' => 'Vídeo pronto · aguardando aprovação',
        'aprovado' => 'Aprovado',
        'reprovado' => 'Reprovado',
        'erro' => 'Erro',
    ];

    protected $table = 'kz_criativos';

    protected $guarded = ['id'];

    protected $casts = [
        'briefing' => 'array',
    ];

    public function oportunidade(): BelongsTo
    {
        return $this->belongsTo(KzOportunidade::class, 'kz_oportunidade_id');
    }

    public function anuncio(): BelongsTo
    {
        return $this->belongsTo(KzOportunidadeAnuncio::class, 'kz_oportunidade_anuncio_id');
    }
}

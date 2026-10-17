<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cupom do vendedor enviado ao ML pelo admin (ver MercadoLivreCupomService). */
class MercadoLivreCupom extends Model
{
    public const STATUS_CRIADO = 'criado';

    public const STATUS_ERRO = 'erro';

    protected $table = 'mercado_livre_cupons';

    protected $fillable = [
        'user_id',
        'nome',
        'ml_promotion_id',
        'status',
        'payload',
        'resposta',
        'itens_adicionados',
        'itens_com_erro',
        'erro',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'resposta' => 'array',
            'itens_adicionados' => 'integer',
            'itens_com_erro' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

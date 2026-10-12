<?php

namespace App\Modules\Analytics\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteVisit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'visitor_id',
        'user_id',
        'path',
        'referer',
        'user_agent',
        'ip',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'dispositivo',
        'consentiu',
    ];

    protected function casts(): array
    {
        return ['consentiu' => 'boolean'];
    }

    /**
     * Só as visitas que entram na contagem (pedido 2026-10-11): as feitas
     * depois do OK de cookies, em que o visitante é o cookie
     * kazakora_visitor. Sem o OK a linha é só o registro de acesso do
     * Marco Civil e o "visitante" muda a cada sessão, o que repetia a mesma
     * pessoa/IP na conta.
     */
    public function scopeContaveis(Builder $query): Builder
    {
        return $query->where('consentiu', true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Modules\Analytics\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Localização aproximada do visitante pelo IP, uma por sessão (pedido
 * 2026-10-24). Gravada pelo GeoDoVisitante; apagada aos 6 meses
 * (privacidade:limpar). Base para o frete pela localização, que ainda não
 * usa estes dados.
 */
class VisitanteGeo extends Model
{
    protected $table = 'visitante_geolocalizacoes';

    public const FONTE_CLOUDFLARE = 'cloudflare';

    public const FONTE_DBIP = 'dbip';

    protected $fillable = [
        'site',
        'sessao',
        'visitor_id',
        'user_id',
        'ip',
        'fonte',
        'pais',
        'uf',
        'cidade',
        'cep',
        'latitude',
        'longitude',
        'fuso',
    ];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Modules\Analytics\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Visitantes por dia, sem repetir a mesma pessoa (pedido 2026-10-11):
     *
     * - Com OK de cookies: o visitante é o cookie kazakora_visitor (fator
     *   principal). Dois cookies no mesmo IP são duas pessoas (casa, 4G).
     * - Sem OK: o código da sessão muda a cada sessão (ids 10847/10848, mesmo
     *   IP no mesmo segundo, contavam 2), então vale o IP: um visitante por
     *   IP no dia.
     * - IP sem OK no dia em que o mesmo IP tem cookie entra nesse cookie
     *   (quem navegou antes de dar OK não conta duas vezes).
     *
     * Devolve uma linha por dia e visitante: dia, visitante, paginas e
     * equipe (alguma página logado como equipe).
     *
     * @param  array<int, int>  $equipe  ids de usuário da equipe
     * @return Collection<int, object>
     */
    public static function visitantesPorDia(Builder $query, array $equipe = []): Collection
    {
        $linhas = $query
            ->selectRaw('DATE(created_at) as dia, visitor_id, ip, consentiu, user_id, COUNT(*) as paginas')
            ->groupBy(DB::raw('DATE(created_at)'), 'visitor_id', 'ip', 'consentiu', 'user_id')
            ->toBase()
            ->get();

        return $linhas
            ->groupBy(fn ($l) => substr((string) $l->dia, 0, 10))
            ->flatMap(function (Collection $doDia, string $dia) use ($equipe) {
                $cookieDoIp = $doDia
                    ->filter(fn ($l) => (bool) $l->consentiu && $l->ip !== null)
                    ->sortBy('visitor_id')
                    ->groupBy('ip')
                    ->map(fn (Collection $g) => (string) $g->first()->visitor_id);

                return $doDia
                    ->groupBy(fn ($l) => match (true) {
                        (bool) $l->consentiu || $l->ip === null => (string) $l->visitor_id,
                        default => $cookieDoIp->get($l->ip, 'ip:'.$l->ip),
                    })
                    ->map(fn (Collection $g, string $visitante) => (object) [
                        'dia' => $dia,
                        'visitante' => $visitante,
                        'paginas' => (int) $g->sum('paginas'),
                        'equipe' => $g->contains(fn ($l) => $l->user_id !== null && in_array((int) $l->user_id, $equipe, true)),
                    ])
                    ->values();
            })
            ->values();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

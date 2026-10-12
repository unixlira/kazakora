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

    /** Acima disso num dia, o "visitante" é robô ou aba presa recarregando (ex.: 1.737 /entrar em 09/10/2026). */
    public const VISITAS_DIA_ROBO = 300;

    /**
     * Coluna site (pedido 2026-10-12): o banco é compartilhado com os outros
     * sites do grupo, que avisam as visitas em /api/{site}/visita. A loja
     * (painel, Visitas Casa Cora, vitrine) só lê site = kazakora.
     */
    public const SITE_LOJA = 'kazakora';

    /** Outros sites: chave gravada em site => nome na tela. */
    public const OUTROS_SITES = [
        'ferrakora' => 'FerraKora',
        'beautykora' => 'Beauty Kora',
    ];

    protected $fillable = [
        'site',
        'visitor_id',
        'user_id',
        'path',
        'referer',
        'user_agent',
        'ip',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'origem',
        'click_id_param',
        'click_id',
        'dispositivo',
        'consentiu',
        'cookies',
    ];

    protected function casts(): array
    {
        return ['consentiu' => 'boolean', 'cookies' => 'array'];
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
     * Devolve uma linha por dia e visitante: dia, visitante, paginas,
     * equipe (alguma página logado como equipe) e origens (os pares
     * visitor_id/ip daquele dia que formam o visitante, para achar as
     * linhas de volta no Log de visitas).
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
                        'origens' => $g->map(fn ($l) => [(string) $l->visitor_id, $l->ip])->unique()->values()->all(),
                    ])
                    ->values();
            })
            ->values();
    }

    /**
     * Só gente de fora (regra do painel, pedido 2026-10-11): sai o
     * visitante que entrou como equipe em algum dia do período, com tudo o
     * que o mesmo navegador visitou, e o visitante que passou de
     * VISITAS_DIA_ROBO páginas num dia. Recebe e devolve linhas de
     * visitantesPorDia().
     *
     * @param  Collection<int, object>  $porVisitante
     * @return Collection<int, object>
     */
    public static function soDeFora(Collection $porVisitante): Collection
    {
        $daEquipe = $porVisitante->where('equipe', true)->pluck('visitante')->unique()->all();

        return $porVisitante
            ->whereNotIn('visitante', $daEquipe)
            ->filter(fn ($l) => $l->paginas <= self::VISITAS_DIA_ROBO);
    }

    /** Só as visitas da loja (kazakora.com). */
    public function scopeDaLoja(Builder $query): Builder
    {
        return $query->where('site_visits.site', self::SITE_LOJA);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

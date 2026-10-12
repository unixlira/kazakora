<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sistema › Visitas ao site (pedido 2026-10-12): o log das páginas abertas
 * na loja, com o IP de quem abriu. Lê a tabela site_visits que o
 * TrackSiteVisit já grava; não grava nada e não muda o rastreamento.
 *
 * Visita com mais de 6 meses volta sem IP e sem navegador
 * (privacidade:limpar): a tela mostra o que existir.
 *
 * "Esconder equipe e robôs" usa a mesma regra do painel
 * (SiteVisit::soDeFora), aplicada ao período escolhido.
 */
class SiteVisitLogController extends Controller
{
    private const POR_PAGINA = 50;

    /** Período máximo de uma consulta: a regra de equipe/robô agrupa o período inteiro. */
    private const MAX_DIAS = 92;

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'ip' => ['nullable', 'string', 'max:45'],
            'pagina' => ['nullable', 'string', 'max:200'],
            'todos' => ['nullable', 'boolean'],
        ]);

        $hoje = Carbon::today();
        $de = isset($validated['de']) ? Carbon::createFromFormat('!Y-m-d', $validated['de']) : $hoje->copy();
        $ate = isset($validated['ate']) ? Carbon::createFromFormat('!Y-m-d', $validated['ate']) : $de->copy();
        if ($ate->lessThan($de)) {
            [$de, $ate] = [$ate, $de];
        }
        if ($de->diffInDays($ate) > self::MAX_DIAS - 1) {
            $de = $ate->copy()->subDays(self::MAX_DIAS - 1);
        }
        $fim = $ate->copy()->addDay();

        $ip = trim((string) ($validated['ip'] ?? ''));
        $pagina = trim((string) ($validated['pagina'] ?? ''));
        $esconder = ! (bool) ($validated['todos'] ?? false);

        $periodo = fn (): Builder => SiteVisit::query()
            ->where('created_at', '>=', $de)
            ->where('created_at', '<', $fim);

        $query = $periodo()
            ->when($ip !== '', fn (Builder $q) => filter_var($ip, FILTER_VALIDATE_IP)
                ? $q->where('ip', $ip)
                : $q->where('ip', 'like', $this->like($ip).'%'))
            ->when($pagina !== '', fn (Builder $q) => $q->where('path', 'like', '%'.$this->like($pagina).'%'));

        $escondidas = 0;
        if ($esconder) {
            $antes = (clone $query)->count();
            $this->tirarEquipeERobos($query, $periodo());
            $escondidas = $antes - (clone $query)->count();
        }

        $totais = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as visitas, COUNT(DISTINCT ip) as ips')
            ->first();

        $equipe = User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $site = rtrim((string) config('app.url'), '/');

        $visitas = $query
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (SiteVisit $v) => [
                'id' => $v->id,
                'quando' => $v->created_at?->format('d/m/Y H:i:s'),
                'path' => $v->path,
                'url' => $site.$v->path,
                'ip' => $v->ip,
                'cliente' => $v->user?->name,
                'equipe' => $v->user_id !== null && in_array((int) $v->user_id, $equipe, true),
                'dispositivo' => $v->dispositivo,
                'origem' => $this->origem($v),
                'navegador' => $this->navegador($v->user_agent),
                'consentiu' => (bool) $v->consentiu,
            ]);

        return Inertia::render('Admin/VisitasSite/Index', [
            'visitas' => $visitas,
            'totais' => [
                'visitas' => (int) ($totais->visitas ?? 0),
                'ips' => (int) ($totais->ips ?? 0),
                'escondidas' => $escondidas,
            ],
            'filtros' => [
                'de' => $de->toDateString(),
                'ate' => $ate->toDateString(),
                'ip' => $ip,
                'pagina' => $pagina,
                'todos' => ! $esconder,
            ],
            'hoje' => $hoje->toDateString(),
            'maxDias' => self::MAX_DIAS,
        ]);
    }

    /**
     * Tira da consulta as linhas dos visitantes que o painel não conta
     * (equipe no período e robô no dia). visitantesPorDia devolve, para
     * cada visitante fora da conta, os pares visitor_id/ip daquele dia; o
     * mesmo par num dia é sempre o mesmo visitante.
     */
    private function tirarEquipeERobos(Builder $query, Builder $periodo): void
    {
        $equipe = User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $porVisitante = SiteVisit::visitantesPorDia($periodo, $equipe);
        $ficam = SiteVisit::soDeFora($porVisitante)
            ->map(fn ($l) => $l->dia.'|'.$l->visitante)
            ->flip();

        /** @var Collection<string, Collection<int, array{0: string, 1: ?string}>> $foraPorDia */
        $foraPorDia = $porVisitante
            ->reject(fn ($l) => $ficam->has($l->dia.'|'.$l->visitante))
            ->groupBy('dia')
            ->map(fn (Collection $doDia) => $doDia->flatMap(fn ($l) => $l->origens)->unique()->values());

        if ($foraPorDia->isEmpty()) {
            return;
        }

        $query->whereNot(function (Builder $q) use ($foraPorDia) {
            foreach ($foraPorDia as $dia => $origens) {
                $inicio = Carbon::createFromFormat('!Y-m-d', $dia);
                $q->orWhere(function (Builder $doDia) use ($inicio, $origens) {
                    $doDia->where('created_at', '>=', $inicio)
                        ->where('created_at', '<', $inicio->copy()->addDay())
                        ->where(function (Builder $pares) use ($origens) {
                            foreach ($origens->groupBy(fn ($par) => (string) $par[1]) as $origensDoIp) {
                                $ipDoPar = $origensDoIp->first()[1];
                                $pares->orWhere(fn (Builder $par) => $par
                                    ->whereIn('visitor_id', $origensDoIp->pluck(0)->unique()->values()->all())
                                    ->when($ipDoPar === null,
                                        fn (Builder $w) => $w->whereNull('ip'),
                                        fn (Builder $w) => $w->where('ip', $ipDoPar)));
                            }
                        });
                });
            }
        });
    }

    private function like(string $texto): string
    {
        return addcslashes($texto, '\\%_');
    }

    /** UTM primeiro (campanha marcada), senão o site de onde veio, senão acesso direto. */
    private function origem(SiteVisit $v): ?string
    {
        $utm = array_filter([$v->utm_source, $v->utm_medium, $v->utm_campaign]);
        if ($utm !== []) {
            return implode(' / ', $utm);
        }

        if ($v->referer) {
            $host = parse_url($v->referer, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? preg_replace('/^www\./', '', $host) : $v->referer;
        }

        return null;
    }

    /** "Chrome · Android". Sem o OK de cookies o navegador não é gravado. */
    private function navegador(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $navegador = match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//', $userAgent) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $userAgent) => 'Opera',
            (bool) preg_match('/SamsungBrowser/', $userAgent) => 'Samsung Internet',
            (bool) preg_match('/Instagram/', $userAgent) => 'Instagram',
            (bool) preg_match('/FBAN|FBAV/', $userAgent) => 'Facebook',
            (bool) preg_match('/Firefox|FxiOS/', $userAgent) => 'Firefox',
            (bool) preg_match('/Chrome|CriOS/', $userAgent) => 'Chrome',
            (bool) preg_match('/Safari/', $userAgent) => 'Safari',
            default => 'Outro',
        };

        $sistema = match (true) {
            (bool) preg_match('/Android/', $userAgent) => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $userAgent) => 'iOS',
            (bool) preg_match('/Windows/', $userAgent) => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/', $userAgent) => 'macOS',
            (bool) preg_match('/CrOS/', $userAgent) => 'ChromeOS',
            (bool) preg_match('/Linux/', $userAgent) => 'Linux',
            default => null,
        };

        return $sistema ? $navegador.' · '.$sistema : $navegador;
    }
}

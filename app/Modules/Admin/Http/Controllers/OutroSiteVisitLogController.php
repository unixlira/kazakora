<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sistema › Visitas FerraKora e Visitas Beauty Kora (pedido 2026-10-12):
 * o log das visitas aos outros sites do grupo, no layout de Visitas
 * KazaKora (SiteVisitLogController), com Data/Hora, IP, Cliente e Ações.
 * Lê site_visits com site = o da rota (gravada por /api/{site}/visita).
 *
 * Uma linha por IP no período, com a hora do primeiro acesso. O olho mostra
 * os horários de cada página aberta; a lixeira apaga as visitas do IP no
 * período (fica na auditoria).
 *
 * Cliente: esses sites não têm login no KazaKora, então "Sim" é o IP que entrou logado
 * como cliente (não equipe) no kazakora.com nos últimos 6 meses
 * (site_visits.user_id). O nome aparece ao passar o mouse.
 */
class OutroSiteVisitLogController extends Controller
{
    private const POR_PAGINA = 50;

    private const MAX_DIAS = 92;

    /** Linhas por página no histórico do olho. */
    private const POR_PAGINA_HISTORICO = 100;

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'ip' => ['nullable', 'string', 'max:45'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$de, $ate, $fim] = $this->periodo($validated);
        $ip = trim((string) ($validated['ip'] ?? ''));

        $site = $this->site($request);
        $query = $this->doSite($site)
            ->where('created_at', '>=', $de)
            ->where('created_at', '<', $fim)
            ->when($ip !== '', fn (Builder $q) => filter_var($ip, FILTER_VALIDATE_IP)
                ? $q->where('ip', $ip)
                : $q->where('ip', 'like', addcslashes($ip, '\\%_').'%'));

        $totais = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as visitas, COUNT(DISTINCT ip) as ips')
            ->first();

        $porIp = (clone $query)->toBase()
            ->selectRaw('ip, MIN(created_at) as primeiro, COUNT(*) as paginas')
            ->groupBy('ip');

        $total = DB::query()->fromSub(clone $porIp, 'visitantes')->count();
        $paginaAtual = max(1, min((int) ($validated['page'] ?? 1), (int) max(1, ceil($total / self::POR_PAGINA))));

        $linhas = $porIp
            ->orderByDesc('primeiro')
            ->orderBy('ip')
            ->forPage($paginaAtual, self::POR_PAGINA)
            ->get();

        $clientes = $this->clientesPorIp($linhas->pluck('ip')->filter()->unique()->values());

        $visitas = (new LengthAwarePaginator(
            $linhas->map(fn ($l) => [
                'chave' => $l->ip ?? 'anonimizado',
                'quando' => Carbon::parse($l->primeiro)->format('d/m/Y H:i:s'),
                'ip' => $l->ip,
                'paginas' => (int) $l->paginas,
                'cliente' => $l->ip !== null ? ($clientes[$l->ip] ?? null) : null,
            ])->values(),
            $total,
            self::POR_PAGINA,
            $paginaAtual,
            ['path' => $request->url(), 'pageName' => 'page'],
        ))->withQueryString();

        return Inertia::render('Admin/VisitasOutroSite/Index', [
            'site' => $site,
            'nome' => SiteVisit::OUTROS_SITES[$site],
            'visitas' => $visitas,
            'totais' => [
                'visitas' => (int) ($totais->visitas ?? 0),
                'ips' => (int) ($totais->ips ?? 0),
                'visitantes' => $total,
            ],
            'filtros' => [
                'de' => $de->toDateString(),
                'ate' => $ate->toDateString(),
                'ip' => $ip,
            ],
            'hoje' => Carbon::today()->toDateString(),
            'maxDias' => self::MAX_DIAS,
        ]);
    }

    /** O olho: os horários das páginas que o IP abriu no período, paginados. */
    public function visitante(Request $request): JsonResponse
    {
        $validated = $this->validarVisitante($request);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        [$de, $ate, $fim] = $this->periodo($validated);

        $query = $this->doSite($this->site($request))
            ->where('created_at', '>=', $de)
            ->where('created_at', '<', $fim)
            ->where('ip', $validated['ip']);

        $total = (clone $query)->count();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA_HISTORICO));
        $paginaAtual = min((int) $request->input('page', 1), $paginas);
        $cliente = $this->clientesPorIp(collect([$validated['ip']]))[$validated['ip']] ?? null;

        return response()->json([
            'ip' => $validated['ip'],
            'de' => $de->format('d/m/Y'),
            'ate' => $ate->format('d/m/Y'),
            'total' => $total,
            'pagina' => $paginaAtual,
            'paginas' => $paginas,
            'por_pagina' => self::POR_PAGINA_HISTORICO,
            'cliente' => $cliente,
            'visitas' => $query->orderBy('created_at')->orderBy('id')->forPage($paginaAtual, self::POR_PAGINA_HISTORICO)->get()
                ->map(fn (SiteVisit $v) => ['id' => $v->id, 'quando' => $v->created_at?->format('d/m/Y H:i:s')]),
        ]);
    }

    /** A lixeira: apaga todas as visitas do IP no período e registra na auditoria. */
    public function apagar(Request $request): RedirectResponse
    {
        $validated = $this->validarVisitante($request);
        [$de, $ate, $fim] = $this->periodo($validated);

        $site = $this->site($request);
        $apagadas = DB::transaction(fn () => $this->doSite($site)
            ->where('created_at', '>=', $de)
            ->where('created_at', '<', $fim)
            ->where('ip', $validated['ip'])
            ->delete());

        $dados = [
            'site' => $site,
            'ip' => $validated['ip'],
            'de' => $de->toDateString(),
            'ate' => $ate->toDateString(),
            'apagadas' => $apagadas,
        ];

        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => AuditLog::ACTION_DELETE,
            'entity' => 'SiteVisit',
            'entity_id' => null,
            'old_values' => $dados,
            'new_values' => ['ip_de_quem_apagou' => $request->ip()],
            'created_at' => now(),
        ]);
        Log::info('Visitas de '.SiteVisit::OUTROS_SITES[$site].' apagadas pelo admin', [...$dados, 'user_id' => $request->user()?->id]);

        return back()->with('success', sprintf(
            '%s %s do IP %s apagada%s (%s a %s).',
            number_format($apagadas, 0, ',', '.'),
            $apagadas === 1 ? 'visita' : 'visitas',
            $validated['ip'],
            $apagadas === 1 ? '' : 's',
            $de->format('d/m'),
            $ate->format('d/m'),
        ));
    }

    /** Site da rota (visitas-ferrakora, visitas-beautykora): ->defaults('site', ...). */
    private function site(Request $request): string
    {
        $site = (string) $request->route('site');
        abort_unless(array_key_exists($site, SiteVisit::OUTROS_SITES), 404);

        return $site;
    }

    private function doSite(string $site): Builder
    {
        return SiteVisit::query()->where('site', $site);
    }

    /** @return array<string, mixed> */
    private function validarVisitante(Request $request): array
    {
        return $request->validate([
            'de' => ['required', 'date_format:Y-m-d'],
            'ate' => ['required', 'date_format:Y-m-d'],
            'ip' => ['required', 'ip'],
        ]);
    }

    /**
     * Nome do cliente (não equipe) que entrou logado no kazakora.com com
     * cada IP nos últimos 6 meses; o acesso mais recente vale.
     *
     * @param  Collection<int, string>  $ips
     * @return array<string, string>
     */
    private function clientesPorIp(Collection $ips): array
    {
        if ($ips->isEmpty()) {
            return [];
        }

        $ultimos = SiteVisit::query()->daLoja()->toBase()
            ->join('users', 'users.id', '=', 'site_visits.user_id')
            ->whereIn('site_visits.ip', $ips->all())
            ->whereNotIn('users.role', User::STAFF_ROLES)
            ->where('site_visits.created_at', '>=', now()->subMonths(6))
            ->selectRaw('site_visits.ip, MAX(site_visits.id) as ultima')
            ->groupBy('site_visits.ip')
            ->pluck('ultima', 'ip');

        if ($ultimos->isEmpty()) {
            return [];
        }

        return SiteVisit::query()->toBase()
            ->join('users', 'users.id', '=', 'site_visits.user_id')
            ->whereIn('site_visits.id', $ultimos->values()->all())
            ->pluck('users.name', 'site_visits.ip')
            ->all();
    }

    /**
     * Período do filtro (padrão hoje), no máximo MAX_DIAS. $fim é o dia
     * seguinte ao "até", para comparar com created_at.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Carbon, 1: Carbon, 2: Carbon}
     */
    private function periodo(array $validated): array
    {
        $hoje = Carbon::today();
        $de = isset($validated['de']) ? Carbon::createFromFormat('!Y-m-d', $validated['de']) : $hoje->copy();
        $ate = isset($validated['ate']) ? Carbon::createFromFormat('!Y-m-d', $validated['ate']) : $de->copy();
        if ($ate->lessThan($de)) {
            [$de, $ate] = [$ate, $de];
        }
        if ($de->diffInDays($ate) > self::MAX_DIAS - 1) {
            $de = $ate->copy()->subDays(self::MAX_DIAS - 1);
        }

        return [$de, $ate, $ate->copy()->addDay()];
    }
}

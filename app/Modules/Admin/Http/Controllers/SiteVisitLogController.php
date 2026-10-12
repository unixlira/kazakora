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
 * Sistema › Visitas ao site (pedido 2026-10-12): o log das visitas à loja,
 * com o IP de quem abriu. Lê a tabela site_visits que o TrackSiteVisit já
 * grava; não grava nada e não muda o rastreamento.
 *
 * A lista tem uma linha por visitante no período (o IP; visita anonimizada,
 * sem IP, agrupa pelo visitor_id), com a hora do primeiro acesso. O olho
 * abre o histórico do visitante (visitante()) e a lixeira apaga as visitas
 * dele no período (apagar(), decisão do Lira em 12/10/2026).
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

    /** Linhas no histórico de um visitante (robô passa de mil páginas num dia). */
    private const MAX_HISTORICO = 500;

    /** Agrupa visitas sem IP (anonimizadas) pelo cookie; com IP, o IP basta. */
    private const CHAVE_ANONIMO = 'CASE WHEN ip IS NULL THEN visitor_id END';

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'ip' => ['nullable', 'string', 'max:45'],
            'pagina' => ['nullable', 'string', 'max:200'],
            'todos' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$de, $ate, $fim] = $this->periodo($validated);
        $hoje = Carbon::today();

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

        // Uma linha por visitante. Só agregados no SELECT (ONLY_FULL_GROUP_BY
        // do MariaDB), e o total conta os grupos numa subconsulta.
        $porVisitante = (clone $query)->toBase()
            ->selectRaw('ip, MIN('.self::CHAVE_ANONIMO.') as anonimo, MIN(created_at) as primeiro, COUNT(*) as paginas, MAX(user_id) as user_id')
            ->groupBy('ip', DB::raw(self::CHAVE_ANONIMO));

        $total = DB::query()->fromSub(clone $porVisitante, 'visitantes')->count();
        $paginaAtual = max(1, min((int) ($validated['page'] ?? 1), (int) max(1, ceil($total / self::POR_PAGINA))));

        $linhas = $porVisitante
            ->orderByDesc('primeiro')
            ->orderBy('ip')
            ->orderBy('anonimo')
            ->forPage($paginaAtual, self::POR_PAGINA)
            ->get();

        $nomes = User::query()->whereIn('id', $linhas->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');
        $equipe = $this->equipe();
        $apagaveis = $this->contarApagaveis($linhas, $de, $fim);

        $visitas = (new LengthAwarePaginator(
            $linhas->map(fn ($l) => [
                'chave' => $l->ip ?? 'v:'.$l->anonimo,
                'quando' => Carbon::parse($l->primeiro)->format('d/m/Y H:i:s'),
                'ip' => $l->ip,
                'visitor_id' => $l->ip === null ? $l->anonimo : null,
                'paginas' => (int) $l->paginas,
                'cliente' => $l->user_id !== null ? ($nomes[$l->user_id] ?? 'Cliente') : null,
                'equipe' => $l->user_id !== null && in_array((int) $l->user_id, $equipe, true),
                'apagaveis' => $apagaveis[$l->ip ?? 'v:'.$l->anonimo] ?? 0,
            ])->values(),
            $total,
            self::POR_PAGINA,
            $paginaAtual,
            ['path' => $request->url(), 'pageName' => 'page'],
        ))->withQueryString();

        return Inertia::render('Admin/VisitasSite/Index', [
            'visitas' => $visitas,
            'totais' => [
                'visitas' => (int) ($totais->visitas ?? 0),
                'ips' => (int) ($totais->ips ?? 0),
                'visitantes' => $total,
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
     * O olho: todas as páginas que o visitante abriu no período, em ordem
     * de horário. Segue o "esconder equipe e robôs" da lista, para o
     * primeiro acesso bater com a linha clicada. O filtro de página não
     * entra: aqui é o caminho inteiro da pessoa.
     */
    public function visitante(Request $request): JsonResponse
    {
        $validated = $this->validarVisitante($request, ['todos' => ['nullable', 'boolean']]);
        [$de, $ate, $fim] = $this->periodo($validated);

        $periodo = fn (): Builder => SiteVisit::query()
            ->where('created_at', '>=', $de)
            ->where('created_at', '<', $fim);

        $query = $this->doVisitante($periodo(), $validated);
        if (! (bool) ($validated['todos'] ?? false)) {
            $this->tirarEquipeERobos($query, $periodo());
        }

        $total = (clone $query)->count();
        $equipe = $this->equipe();
        $site = rtrim((string) config('app.url'), '/');

        $visitas = $query
            ->with('user:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_HISTORICO)
            ->get()
            ->map(fn (SiteVisit $v) => [
                'id' => $v->id,
                'quando' => $v->created_at?->format('d/m/Y H:i:s'),
                'path' => $v->path,
                'url' => $site.$v->path,
                'cliente' => $v->user?->name,
                'equipe' => $v->user_id !== null && in_array((int) $v->user_id, $equipe, true),
                'dispositivo' => $v->dispositivo,
                'origem' => $this->origem($v),
                'navegador' => $this->navegador($v->user_agent),
                'consentiu' => (bool) $v->consentiu,
            ]);

        return response()->json([
            'ip' => $validated['ip'] ?? null,
            'visitor_id' => isset($validated['ip']) ? null : $validated['visitor_id'],
            'de' => $de->format('d/m/Y'),
            'ate' => $ate->format('d/m/Y'),
            'total' => $total,
            'limite' => self::MAX_HISTORICO,
            'visitas' => $visitas,
        ]);
    }

    /**
     * A lixeira (decisão do Lira, 12/10/2026): apaga TODAS as visitas
     * daquele IP (ou do visitor_id, se o IP foi anonimizado) dentro do
     * período do filtro, e nada fora dele. Não olha os outros filtros
     * (página, equipe/robô): o que o diálogo conta é o que sai. Fica
     * registrado na auditoria quem apagou.
     */
    public function apagar(Request $request): RedirectResponse
    {
        $validated = $this->validarVisitante($request);
        [$de, $ate, $fim] = $this->periodo($validated);

        $query = $this->doVisitante(
            SiteVisit::query()->where('created_at', '>=', $de)->where('created_at', '<', $fim),
            $validated,
        );

        $apagadas = DB::transaction(fn () => (clone $query)->delete());

        $alvo = isset($validated['ip']) ? 'IP '.$validated['ip'] : 'visitante anonimizado '.$validated['visitor_id'];
        $dados = [
            'ip' => $validated['ip'] ?? null,
            'visitor_id' => isset($validated['ip']) ? null : $validated['visitor_id'],
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
        Log::info('Visitas ao site apagadas pelo admin', [...$dados, 'user_id' => $request->user()?->id]);

        return back()->with('success', sprintf(
            '%s %s do %s apagada%s (%s a %s).',
            number_format($apagadas, 0, ',', '.'),
            $apagadas === 1 ? 'visita' : 'visitas',
            $alvo,
            $apagadas === 1 ? '' : 's',
            $de->format('d/m'),
            $ate->format('d/m'),
        ));
    }

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function validarVisitante(Request $request, array $extras = []): array
    {
        return $request->validate([
            'de' => ['required', 'date_format:Y-m-d'],
            'ate' => ['required', 'date_format:Y-m-d'],
            'ip' => ['nullable', 'required_without:visitor_id', 'ip'],
            'visitor_id' => ['nullable', 'required_without:ip', 'string', 'max:64'],
            ...$extras,
        ]);
    }

    /** IP exato; sem IP (anonimizada), as linhas sem IP daquele visitor_id. */
    private function doVisitante(Builder $query, array $validated): Builder
    {
        return isset($validated['ip'])
            ? $query->where('ip', $validated['ip'])
            : $query->whereNull('ip')->where('visitor_id', $validated['visitor_id']);
    }

    /**
     * Quantas linhas a lixeira de cada visitante da página apagaria: todas
     * as do período, sem os outros filtros. Uma consulta para a página toda.
     *
     * @return array<string, int>
     */
    private function contarApagaveis(Collection $linhas, Carbon $de, Carbon $fim): array
    {
        if ($linhas->isEmpty()) {
            return [];
        }

        $ips = $linhas->pluck('ip')->filter()->unique()->values()->all();
        $anonimos = $linhas->whereNull('ip')->pluck('anonimo')->filter()->unique()->values()->all();

        return SiteVisit::query()->toBase()
            ->where('created_at', '>=', $de)
            ->where('created_at', '<', $fim)
            ->where(fn ($q) => $q
                ->whereIn('ip', $ips)
                ->orWhere(fn ($s) => $s->whereNull('ip')->whereIn('visitor_id', $anonimos)))
            ->selectRaw('ip, MIN('.self::CHAVE_ANONIMO.') as anonimo, COUNT(*) as total')
            ->groupBy('ip', DB::raw(self::CHAVE_ANONIMO))
            ->get()
            ->mapWithKeys(fn ($l) => [($l->ip ?? 'v:'.$l->anonimo) => (int) $l->total])
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

    /** @return array<int, int> */
    private function equipe(): array
    {
        return User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Tira da consulta as linhas dos visitantes que o painel não conta
     * (equipe no período e robô no dia). visitantesPorDia devolve, para
     * cada visitante fora da conta, os pares visitor_id/ip daquele dia; o
     * mesmo par num dia é sempre o mesmo visitante.
     */
    private function tirarEquipeERobos(Builder $query, Builder $periodo): void
    {
        $porVisitante = SiteVisit::visitantesPorDia($periodo, $this->equipe());
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

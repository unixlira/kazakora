<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Models\User;
use App\Modules\Admin\Models\AdminClique;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contador de cliques do painel (pedido do Lira 2026-10-25): quais menus,
 * abas e botões do admin a equipe realmente usa.
 *
 * - POST /admin/cliques: o AdminLayout manda em lote (fetch keepalive, com
 *   CSRF e sessão de quem está logado no painel). Lista fechada de campos;
 *   só o nome do elemento, nunca o conteúdo de campo. Robô: 204 sem gravar.
 * - GET /admin/relatorios/cliques: ranking por período, página e usuário.
 */
class CliquesAdminController extends Controller
{
    public const LIMITE_ITENS = 50;

    public const PERIODOS = ['hoje' => 0, '7' => 6, '30' => 29];

    public function store(Request $request): HttpResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->isStaff() || TrackSiteVisit::naoENavegador($request)) {
            return response()->noContent();
        }

        // Validator direto (sem $request->validate): fora de api/* o erro de
        // validação vira redirect, e o fetch seguiria o redirect à toa.
        $validador = Validator::make($request->all(), [
            'cliques' => ['required', 'array', 'min:1', 'max:'.self::LIMITE_ITENS],
            'cliques.*.elemento' => ['required', 'string', 'max:120'],
            'cliques.*.tipo' => ['required', 'string', 'in:'.implode(',', AdminClique::TIPOS)],
            'cliques.*.pagina' => ['required', 'string', 'max:300'],
            'cliques.*.destino' => ['nullable', 'string', 'max:300'],
            'cliques.*.quantos' => ['required', 'integer', 'min:1', 'max:200'],
        ]);
        if ($validador->fails()) {
            return response()->noContent(422);
        }
        $dados = $validador->validated();

        foreach ($dados['cliques'] as $clique) {
            $elemento = trim(preg_replace('/\s+/u', ' ', strip_tags($clique['elemento'])));
            $pagina = AdminClique::normalizarCaminho($clique['pagina']);
            if ($elemento === '' || $pagina === null) {
                continue;
            }

            try {
                AdminClique::somar(
                    $user->id,
                    mb_substr($elemento, 0, 120),
                    $clique['tipo'],
                    $pagina,
                    AdminClique::normalizarCaminho($clique['destino'] ?? null),
                    (int) $clique['quantos'],
                );
            } catch (\Throwable $e) {
                // Contador é secundário: falha aqui não pode virar erro na tela.
                Log::warning('Contador de cliques do admin falhou', ['erro' => $e->getMessage()]);
            }
        }

        return response()->noContent();
    }

    public function index(Request $request): Response
    {
        $periodo = array_key_exists((string) $request->query('periodo'), self::PERIODOS) ? (string) $request->query('periodo') : '7';
        $hoje = Carbon::now('America/Sao_Paulo')->startOfDay();
        $de = $hoje->copy()->subDays(self::PERIODOS[$periodo])->toDateString();
        $ate = $hoje->toDateString();
        $pagina = $request->filled('pagina') ? (string) $request->query('pagina') : null;
        $userId = $request->filled('user_id') ? $request->integer('user_id') : null;

        $filtrado = fn () => AdminClique::query()
            ->when($pagina, fn ($q) => $q->where('pagina', $pagina))
            ->when($userId, fn ($q) => $q->where('user_id', $userId));

        $noPeriodo = fn () => $filtrado()->whereBetween('dia', [$de, $ate]);

        $ranking = $noPeriodo()
            ->selectRaw('elemento, MAX(tipo) as tipo, MAX(destino) as destino, SUM(contagem) as total, COUNT(DISTINCT user_id) as pessoas, MAX(dia) as ultimo_dia')
            ->groupBy('elemento')
            ->orderByDesc('total')
            ->orderBy('elemento')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'elemento' => $r->elemento,
                'tipo' => $r->tipo,
                'destino' => $r->destino,
                'total' => (int) $r->total,
                'pessoas' => (int) $r->pessoas,
                'ultimoDia' => Carbon::parse($r->ultimo_dia)->format('d/m'),
            ]);

        // Clicados nos últimos 90 dias e nenhuma vez no período escolhido.
        $semCliqueNoPeriodo = $filtrado()
            ->where('dia', '>=', $hoje->copy()->subDays(89)->toDateString())
            ->where('dia', '<', $de)
            ->whereNotIn('elemento', $ranking->pluck('elemento')->all() ?: [''])
            ->selectRaw('elemento, MAX(tipo) as tipo, MAX(dia) as ultimo_dia')
            ->groupBy('elemento')
            ->orderBy('ultimo_dia')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'elemento' => $r->elemento,
                'tipo' => $r->tipo,
                'ultimoDia' => Carbon::parse($r->ultimo_dia)->format('d/m'),
            ]);

        $porPagina = $noPeriodo()
            ->selectRaw('pagina, SUM(contagem) as total')
            ->groupBy('pagina')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['pagina' => $r->pagina, 'total' => (int) $r->total]);

        $nomes = User::query()->whereIn('role', User::STAFF_ROLES)->orderBy('name')->get(['id', 'name']);
        $porUsuario = $noPeriodo()
            ->selectRaw('user_id, SUM(contagem) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'nome' => $nomes->firstWhere('id', $r->user_id)?->name ?? "Usuário #{$r->user_id}",
                'total' => (int) $r->total,
            ]);

        return Inertia::render('Admin/Cliques/Index', [
            'filtros' => ['periodo' => $periodo, 'pagina' => $pagina, 'user_id' => $userId],
            'periodo' => ['de' => Carbon::parse($de)->format('d/m/Y'), 'ate' => Carbon::parse($ate)->format('d/m/Y')],
            'ranking' => $ranking,
            'semCliqueNoPeriodo' => $semCliqueNoPeriodo,
            'porPagina' => $porPagina,
            'porUsuario' => $porUsuario,
            'total' => $ranking->sum('total'),
            'paginas' => AdminClique::query()
                ->where('dia', '>=', $hoje->copy()->subDays(89)->toDateString())
                ->distinct()->orderBy('pagina')->pluck('pagina'),
            'usuarios' => $nomes,
        ]);
    }
}

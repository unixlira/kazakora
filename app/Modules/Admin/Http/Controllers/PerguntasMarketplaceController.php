<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\RascunharRespostaPerguntaMercadoLivre;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Marketplace\Models\MercadoLivrePergunta;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Models\ShopeeChatConversa;
use App\Services\MercadoLivre\Services\RespostaPerguntaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Atendimento > Perguntas MK Place (pedido do Lira 2026-10-20): perguntas dos
 * anúncios dos marketplaces. Cada pergunta guarda o canal de origem (coluna
 * canal); hoje só o Mercado Livre entrega perguntas e aceita resposta pela
 * API (a Shopee e a Amazon não têm perguntas públicas na API). A Manuela atende primeiro; o que ela não sabe fica
 * marcado para uma pessoa, com aviso no topo do admin. O atendente pode
 * assumir qualquer pergunta, editar o rascunho e enviar, ou devolver para ela.
 * Aba "Chat da Shopee" (2026-10-25): conversas do sellerchat na mesma tela
 * (dados em ShopeeChatController::dados).
 */
class PerguntasMarketplaceController extends Controller
{
    private const FILTROS = ['humano', 'rascunho', 'abertas', 'respondidas', 'todas'];

    private const CANAIS = [
        MercadoLivrePergunta::CANAL_MERCADO_LIVRE,
        MercadoLivrePergunta::CANAL_SHOPEE,
        MercadoLivrePergunta::CANAL_AMAZON,
        MercadoLivrePergunta::CANAL_TIKTOK_SHOP,
    ];

    public function index(Request $request): Response
    {
        $abas = [
            'perguntas' => MercadoLivrePergunta::query()->aguardandoHumano()->count(),
            'chat-shopee' => rescue(fn () => ShopeeChatConversa::query()->comNaoLidas()->count(), 0, false),
        ];

        if ($request->query('aba') === 'chat-shopee') {
            return Inertia::render('Admin/PerguntasMarketplace/Index', [
                'aba' => 'chat-shopee',
                'abas' => $abas,
                'chat' => ShopeeChatController::dados($request),
                'perguntas' => [],
            ]);
        }

        $filtro = in_array($request->query('filtro'), self::FILTROS, true) ? $request->query('filtro') : 'humano';
        $busca = trim((string) $request->query('busca', ''));
        $canal = in_array($request->query('canal'), self::CANAIS, true) ? $request->query('canal') : null;

        $perguntas = $this->filtrar(MercadoLivrePergunta::query(), $filtro)
            ->when($canal, fn ($q) => $q->where('canal', $canal))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('pergunta', 'like', "%{$busca}%")->orWhere('item_id', 'like', "%{$busca}%")))
            ->orderByRaw('respondida_em is null desc')
            ->orderByDesc('perguntada_em')->orderByDesc('id')
            ->limit(200)
            ->get();

        // Nome do produto por canal + código do anúncio.
        $produtos = ProductChannelListing::query()
            ->whereIn('channel', $perguntas->pluck('canal')->unique())
            ->whereIn('external_id', $perguntas->pluck('item_id')->unique())
            ->with('product:id,name')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->channel.'|'.$l->external_id => $l->product?->name]);

        $atendentes = User::query()->whereIn('id', $perguntas->pluck('humano_assumiu_por')->merge($perguntas->pluck('respondida_por_user_id'))->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Admin/PerguntasMarketplace/Index', [
            'aba' => 'perguntas',
            'abas' => $abas,
            'perguntas' => $perguntas->map(fn (MercadoLivrePergunta $p) => $this->linha($p, $produtos, $atendentes))->values(),
            'filtro' => $filtro,
            'busca' => $busca,
            'canal' => $canal,
            'canais' => MercadoLivrePergunta::query()->select('canal')->distinct()->pluck('canal')->merge([MercadoLivrePergunta::CANAL_MERCADO_LIVRE])->unique()->values(),
            'contagem' => collect(self::FILTROS)->mapWithKeys(fn ($f) => [$f => $this->filtrar(MercadoLivrePergunta::query(), $f)->count()]),
            'autoResposta' => MercadoLivrePergunta::manuelaRespondeSozinha(),
            'podeConfigurar' => (bool) $request->user()?->isAdmin(),
        ]);
    }

    /**
     * Aviso em tempo real no canto do admin (PerguntasMarketplaceToasts.vue,
     * pedido 2026-10-20): perguntas que a Manuela acabou de marcar para uma
     * pessoa e ninguém assumiu. O cursor é o horário da marca (rascunho_em),
     * porque ela chega depois do Gemini e o id não serve.
     */
    public function chegando(Request $request): JsonResponse
    {
        $agora = now();
        $total = MercadoLivrePergunta::query()->aguardandoHumano()->count();

        if (! $request->filled('desde')) {
            return response()->json(['desde' => $agora->toIso8601String(), 'total' => $total, 'perguntas' => []]);
        }

        try {
            $desde = Carbon::parse((string) $request->query('desde'));
        } catch (Throwable) {
            $desde = $agora;
        }

        $novas = MercadoLivrePergunta::query()
            ->pendentes()
            ->where('precisa_humano', true)
            ->whereNull('humano_assumiu_em')
            ->where('rascunho_em', '>=', $desde->copy()->subSecond())
            ->orderBy('rascunho_em')
            ->limit(10)
            ->get();

        return response()->json([
            'desde' => $agora->toIso8601String(),
            'total' => $total,
            'perguntas' => $novas->map(fn (MercadoLivrePergunta $p) => [
                'id' => $p->id,
                'canal' => $p->canal,
                'item_id' => $p->item_id,
                'pergunta' => mb_substr((string) $p->pergunta, 0, 200),
            ]),
        ]);
    }

    /** Contador do alerta do topo do admin (consultado a cada minuto). */
    public function pendentes(): JsonResponse
    {
        return response()->json(['total' => MercadoLivrePergunta::query()->aguardandoHumano()->count()]);
    }

    public function enviar(Request $request, MercadoLivrePergunta $pergunta, RespostaPerguntaService $respostas): JsonResponse
    {
        $dados = $request->validate(['texto' => ['required', 'string', 'max:'.RespostaPerguntaService::LIMITE]]);

        if (! $pergunta->podeEnviar()) {
            return response()->json(['message' => 'Esse marketplace ainda não aceita resposta pela API. Responda direto no painel dele.'], 422);
        }

        try {
            $respostas->enviar($pergunta, $dados['texto'], MercadoLivrePergunta::POR_HUMANO, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Atendimento humano: a Manuela não envia mais nada nessa pergunta. */
    public function assumir(Request $request, MercadoLivrePergunta $pergunta): JsonResponse
    {
        abort_unless($pergunta->aberta(), 422, 'Essa pergunta já foi respondida.');

        $pergunta->update(['humano_assumiu_por' => $request->user()->id, 'humano_assumiu_em' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Devolve para a Manuela: ela escreve um rascunho novo (e envia, se o automático estiver ligado). */
    public function devolver(MercadoLivrePergunta $pergunta): JsonResponse
    {
        abort_unless($pergunta->aberta(), 422, 'Essa pergunta já foi respondida.');

        $pergunta->update([
            'humano_assumiu_por' => null,
            'humano_assumiu_em' => null,
            'precisa_humano' => false,
            'resposta_rascunho' => null,
            'rascunho_em' => null,
            'erro_envio' => null,
        ]);

        RascunharRespostaPerguntaMercadoLivre::dispatch($pergunta->id);

        return response()->json(['ok' => true]);
    }

    /** Liga/desliga "Manuela responde sozinha" (só admin). */
    public function autoResposta(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $ligar = $request->validate(['ligado' => ['required', 'boolean']])['ligado'];
        Setting::set(MercadoLivrePergunta::SETTING_AUTO, $ligar ? '1' : '0');

        return response()->json(['ligado' => (bool) $ligar]);
    }

    private function filtrar($query, string $filtro)
    {
        return match ($filtro) {
            'humano' => $query->aguardandoHumano(),
            'rascunho' => $query->pendentes()->where('precisa_humano', false)->whereNull('humano_assumiu_em'),
            'abertas' => $query->pendentes(),
            'respondidas' => $query->whereNotNull('respondida_em'),
            default => $query,
        };
    }

    private function linha(MercadoLivrePergunta $p, $produtos, $atendentes): array
    {
        return [
            'id' => $p->id,
            'canal' => $p->canal,
            'pode_enviar' => $p->podeEnviar(),
            'item_id' => $p->item_id,
            'produto' => $produtos[$p->canal.'|'.$p->item_id] ?? null,
            'link' => $p->link(),
            'link_pergunta' => $p->linkDaPergunta(),
            'comprador' => $p->comprador_nome,
            'pergunta' => $p->pergunta,
            'perguntada_em' => $p->perguntada_em?->toIso8601String(),
            'rascunho' => $p->resposta_rascunho,
            'rascunho_em' => $p->rascunho_em?->toIso8601String(),
            'precisa_humano' => $p->precisa_humano,
            'assumida_por' => $p->humano_assumiu_por ? ($atendentes[$p->humano_assumiu_por] ?? 'Atendente') : null,
            'aberta' => $p->aberta(),
            'resposta' => $p->resposta_enviada,
            'respondida_em' => $p->respondida_em?->toIso8601String(),
            'respondida_por' => $p->respondida_por,
            'respondida_por_nome' => $p->respondida_por_user_id ? ($atendentes[$p->respondida_por_user_id] ?? null) : null,
            'erro_envio' => $p->erro_envio,
        ];
    }
}

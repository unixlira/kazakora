<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Marketplace\Models\ShopeeChatConversa;
use App\Modules\Marketplace\Models\ShopeeChatMensagem;
use App\Modules\Marketplace\Support\ShopeeChatService;
use App\Services\Shopee\Exceptions\ShopeeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Atendimento > Shopee: chat do comprador (sellerchat). O sync (shopee:chat-sync,
 * a cada 2 min) traz as conversas; aqui o atendente lê e, se quiser, responde.
 * Não existe resposta automática: toda mensagem enviada parte de um clique.
 */
class ShopeeChatController extends Controller
{
    private const FILTROS = ['nao_lidas', 'todas'];

    public function index(Request $request): Response
    {
        $filtro = in_array($request->query('filtro'), self::FILTROS, true) ? $request->query('filtro') : 'todas';
        $busca = trim((string) $request->query('busca', ''));

        $conversas = ShopeeChatConversa::query()
            ->when($filtro === 'nao_lidas', fn ($q) => $q->comNaoLidas())
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('comprador_nome', 'like', "%{$busca}%")->orWhere('ultima_mensagem_texto', 'like', "%{$busca}%")))
            ->orderByRaw('nao_lidas > 0 desc')
            ->orderByDesc('ultima_mensagem_em')->orderByDesc('id')
            ->limit(200)
            ->get();

        $selecionadaId = (int) $request->query('conversa') ?: $conversas->first()?->id;
        $selecionada = $selecionadaId ? ShopeeChatConversa::query()->find($selecionadaId) : null;

        $mensagens = collect();
        $atendentes = collect();
        if ($selecionada) {
            $mensagens = $selecionada->mensagens()->orderByDesc('enviada_em')->orderByDesc('id')->limit(200)->get()->reverse()->values();
            $atendentes = User::query()->whereIn('id', $mensagens->pluck('enviada_por_user_id')->filter()->unique())->pluck('name', 'id');
        }

        return Inertia::render('Admin/ShopeeChat/Index', [
            'conversas' => $conversas->map(fn (ShopeeChatConversa $c) => $this->linha($c))->values(),
            'selecionada' => $selecionada ? $this->linha($selecionada) : null,
            'mensagens' => $mensagens->map(fn (ShopeeChatMensagem $m) => [
                'id' => $m->id,
                'tipo' => $m->tipo,
                'do_cliente' => $m->do_cliente,
                'texto' => $m->texto,
                'imagem' => $m->tipo === 'image' ? ($m->conteudo['url'] ?? $m->conteudo['thumb_url'] ?? null) : null,
                'enviada_em' => $m->enviada_em?->toIso8601String(),
                'atendente' => $m->enviada_por_user_id ? ($atendentes[$m->enviada_por_user_id] ?? 'Atendente') : null,
            ])->values(),
            'filtro' => $filtro,
            'busca' => $busca,
            'contagem' => [
                'nao_lidas' => ShopeeChatConversa::query()->comNaoLidas()->count(),
                'todas' => ShopeeChatConversa::query()->count(),
            ],
            'ultimaSincronizacao' => ShopeeChatConversa::query()->max('sincronizada_em'),
            'limiteTexto' => ShopeeChatService::LIMITE_TEXTO,
        ]);
    }

    public function pendentes(): JsonResponse
    {
        return response()->json(['total' => ShopeeChatConversa::query()->comNaoLidas()->count()]);
    }

    /** Resposta do atendente. Só por esta rota sai mensagem para o cliente. */
    public function enviar(Request $request, ShopeeChatConversa $conversa, ShopeeChatService $chat): JsonResponse
    {
        $dados = $request->validate(['texto' => ['required', 'string', 'max:'.ShopeeChatService::LIMITE_TEXTO]]);

        try {
            $chat->enviar($conversa, $dados['texto'], $request->user()->id);
        } catch (ShopeeException $e) {
            return response()->json(['message' => 'A Shopee recusou a mensagem: '.$e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function lida(ShopeeChatConversa $conversa, ShopeeChatService $chat): JsonResponse
    {
        try {
            $chat->marcarLida($conversa);
        } catch (ShopeeException $e) {
            return response()->json(['message' => 'A Shopee recusou: '.$e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    private function linha(ShopeeChatConversa $c): array
    {
        return [
            'id' => $c->id,
            'comprador' => $c->comprador_nome,
            'avatar' => $c->comprador_avatar,
            'nao_lidas' => $c->nao_lidas,
            'ultima' => $c->ultima_mensagem_texto,
            'ultima_do_cliente' => $c->ultima_do_cliente,
            'ultima_em' => $c->ultima_mensagem_em?->toIso8601String(),
            'sincronizada_em' => $c->sincronizada_em?->toIso8601String(),
        ];
    }
}

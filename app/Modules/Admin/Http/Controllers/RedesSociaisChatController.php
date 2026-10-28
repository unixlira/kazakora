<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceChatConversa;
use App\Modules\Marketplace\Models\MarketplaceChatMensagem;
use App\Services\Meta\MetaMensagensService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Atendimento › Instagram e Atendimento › Facebook (pedido do Lira
 * 2026-10-27): mensagens de quem chama pelo Direct e pelo Messenger, cada
 * rede na sua tela para saber de onde veio. Copiadas pelo atendimento:meta-sync
 * a cada 2 min. Só leitura: responder continua no Instagram e no Facebook.
 */
class RedesSociaisChatController extends Controller
{
    private const FILTROS = ['nao_lidas', 'todas'];

    /** @return array<string, int> não lidas por rede (contadores do menu) */
    public static function naoLidasPorRede(): array
    {
        $porCanal = rescue(fn () => MarketplaceChatConversa::query()->whereIn('canal', MarketplaceChatConversa::REDES)->comNaoLidas()->selectRaw('canal, count(*) as total')->groupBy('canal')->pluck('total', 'canal')->all(), [], false);

        return collect(MarketplaceChatConversa::REDES)->mapWithKeys(fn (string $rede) => [$rede => (int) ($porCanal[$rede] ?? 0)])->all();
    }

    public function index(Request $request, string $rede): Response
    {
        $filtro = in_array($request->query('filtro'), self::FILTROS, true) ? $request->query('filtro') : 'todas';
        $busca = trim((string) $request->query('busca', ''));

        $base = fn () => MarketplaceChatConversa::query()->doCanal($rede);
        $conversas = $base()
            ->when($filtro === 'nao_lidas', fn ($q) => $q->comNaoLidas())
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('comprador_nome', 'like', "%{$busca}%")->orWhere('ultima_mensagem_texto', 'like', "%{$busca}%")))
            ->orderByRaw('nao_lidas > 0 desc')
            ->orderByDesc('ultima_mensagem_em')->orderByDesc('id')
            ->limit(200)
            ->get();

        $selecionadaId = (int) $request->query('conversa') ?: $conversas->first()?->id;
        $selecionada = $selecionadaId ? $base()->find($selecionadaId) : null;
        $mensagens = $selecionada
            ? $selecionada->mensagens()->orderByDesc('enviada_em')->orderByDesc('id')->limit(200)->get()->reverse()->values()
            : collect();

        return Inertia::render('Admin/RedesSociais/Index', [
            'rede' => $rede,
            'faltando' => MetaMensagensService::faltando($rede),
            'conversas' => $conversas->map(fn (MarketplaceChatConversa $c) => self::linha($c))->values(),
            'selecionada' => $selecionada ? self::linha($selecionada) : null,
            'mensagens' => $mensagens->map(fn (MarketplaceChatMensagem $m) => [
                'id' => $m->id,
                'tipo' => $m->tipo,
                'do_cliente' => $m->do_cliente,
                'texto' => $m->texto,
                'imagem' => $m->tipo === 'image' ? ($m->conteudo['url'] ?? null) : null,
                'enviada_em' => $m->enviada_em?->toIso8601String(),
            ])->values(),
            'filtro' => $filtro,
            'busca' => $busca,
            'contagem' => [
                'nao_lidas' => $base()->comNaoLidas()->count(),
                'todas' => $base()->count(),
            ],
            'ultimaSincronizacao' => $base()->max('sincronizada_em'),
        ]);
    }

    public function lida(string $rede, MarketplaceChatConversa $conversa, MetaMensagensService $meta): JsonResponse
    {
        abort_unless($conversa->canal === $rede, 404);
        $meta->marcarLida($conversa);

        return response()->json(['ok' => true]);
    }

    private static function linha(MarketplaceChatConversa $c): array
    {
        return [
            'id' => $c->id,
            'cliente' => $c->comprador_nome,
            'nao_lidas' => $c->nao_lidas,
            'ultima' => $c->ultima_mensagem_texto,
            'ultima_do_cliente' => $c->ultima_do_cliente,
            'ultima_em' => $c->ultima_mensagem_em?->toIso8601String(),
        ];
    }
}

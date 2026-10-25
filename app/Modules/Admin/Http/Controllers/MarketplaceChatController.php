<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceChatConversa;
use App\Modules\Marketplace\Models\MarketplaceChatMensagem;
use App\Modules\Marketplace\Support\AmazonMensagensService;
use App\Modules\Marketplace\Support\TikTokShopChatService;
use App\Services\TikTokShop\TikTokShopClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Abas "Amazon" e "TikTok Shop" de Perguntas MK Place (pedido do Lira
 * 2026-10-25): mensagens de comprador lidas pelo atendimento:chat-sync (a cada
 * 2 min). Toda resposta parte de um clique do atendente; nada automático.
 * Amazon = e-mail autorizado na Seller Central (AmazonMensagensService);
 * TikTok = Customer Service API do app próprio (TikTokShopChatService).
 */
class MarketplaceChatController extends Controller
{
    private const FILTROS = ['nao_lidas', 'todas'];

    /** Aba da tela => canal. */
    public const ABAS = ['chat-amazon' => MarketplaceChatConversa::AMAZON, 'chat-tiktok' => MarketplaceChatConversa::TIKTOK];

    /** @return array<string, int> não lidas por aba (contador das abas e do menu) */
    public static function naoLidasPorAba(): array
    {
        $porCanal = rescue(fn () => MarketplaceChatConversa::query()->comNaoLidas()->selectRaw('canal, count(*) as total')->groupBy('canal')->pluck('total', 'canal')->all(), [], false);

        return collect(self::ABAS)->map(fn (string $canal) => (int) ($porCanal[$canal] ?? 0))->all();
    }

    /**
     * Dados da aba (PerguntasMarketplaceController::index).
     *
     * @return array<string, mixed>
     */
    public static function dados(Request $request, string $canal): array
    {
        $amazon = $canal === MarketplaceChatConversa::AMAZON;
        $faltando = $amazon ? AmazonMensagensService::faltando() : TikTokShopClient::faltando();
        $conectado = $amazon ? $faltando === [] : TikTokShopClient::conectado();

        $filtro = in_array($request->query('filtro'), self::FILTROS, true) ? $request->query('filtro') : 'todas';
        $busca = trim((string) $request->query('busca', ''));

        $base = fn () => MarketplaceChatConversa::query()->doCanal($canal);
        $conversas = $base()
            ->when($filtro === 'nao_lidas', fn ($q) => $q->comNaoLidas())
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('comprador_nome', 'like', "%{$busca}%")->orWhere('pedido_numero', 'like', "%{$busca}%")->orWhere('ultima_mensagem_texto', 'like', "%{$busca}%")))
            ->orderByRaw('nao_lidas > 0 desc')
            ->orderByDesc('ultima_mensagem_em')->orderByDesc('id')
            ->limit(200)
            ->get();

        $selecionadaId = (int) $request->query('conversa') ?: $conversas->first()?->id;
        $selecionada = $selecionadaId ? $base()->find($selecionadaId) : null;

        $mensagens = collect();
        $atendentes = collect();
        if ($selecionada) {
            $mensagens = $selecionada->mensagens()->orderByDesc('enviada_em')->orderByDesc('id')->limit(200)->get()->reverse()->values();
            $atendentes = User::query()->whereIn('id', $mensagens->pluck('enviada_por_user_id')->filter()->unique())->pluck('name', 'id');
        }

        return [
            'canal' => $canal,
            'configurado' => $conectado,
            'faltando' => $faltando,
            // TikTok: app configurado mas loja ainda não autorizada.
            'conectarUrl' => ! $amazon && $faltando === [] && ! $conectado ? '/admin/integracoes/tiktok-shop/conectar' : null,
            'conversas' => $conversas->map(fn (MarketplaceChatConversa $c) => self::linha($c))->values(),
            'selecionada' => $selecionada ? self::linha($selecionada) : null,
            'mensagens' => $mensagens->map(fn (MarketplaceChatMensagem $m) => [
                'id' => $m->id,
                'tipo' => $m->tipo,
                'do_cliente' => $m->do_cliente,
                'texto' => $m->texto,
                'imagem' => $m->tipo === 'image' ? ($m->conteudo['url'] ?? null) : null,
                'enviada_em' => $m->enviada_em?->toIso8601String(),
                'atendente' => $m->enviada_por_user_id ? ($atendentes[$m->enviada_por_user_id] ?? 'Atendente') : null,
            ])->values(),
            'filtro' => $filtro,
            'busca' => $busca,
            'contagem' => [
                'nao_lidas' => $base()->comNaoLidas()->count(),
                'todas' => $base()->count(),
            ],
            'ultimaSincronizacao' => $base()->max('sincronizada_em'),
            'limiteTexto' => $amazon ? AmazonMensagensService::LIMITE_TEXTO : TikTokShopChatService::LIMITE_TEXTO,
        ];
    }

    /** Resposta do atendente. Só por esta rota sai mensagem para o comprador. */
    public function enviar(Request $request, string $canal, MarketplaceChatConversa $conversa): JsonResponse
    {
        abort_unless($conversa->canal === $canal, 404);
        $amazon = $canal === MarketplaceChatConversa::AMAZON;
        $limite = $amazon ? AmazonMensagensService::LIMITE_TEXTO : TikTokShopChatService::LIMITE_TEXTO;
        $dados = $request->validate(['texto' => ['required', 'string', 'max:'.$limite]]);

        try {
            $amazon
                ? app(AmazonMensagensService::class)->enviar($conversa, $dados['texto'], $request->user()->id)
                : app(TikTokShopChatService::class)->enviar($conversa, $dados['texto'], $request->user()->id);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => ($amazon ? 'O e-mail não saiu: ' : 'O TikTok recusou a mensagem: ').Str::limit($e->getMessage(), 200)], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function lida(string $canal, MarketplaceChatConversa $conversa): JsonResponse
    {
        abort_unless($conversa->canal === $canal, 404);

        try {
            $canal === MarketplaceChatConversa::TIKTOK
                ? app(TikTokShopChatService::class)->marcarLida($conversa)
                : $conversa->update(['nao_lidas' => 0]);
        } catch (Throwable $e) {
            return response()->json(['message' => 'O TikTok recusou: '.Str::limit($e->getMessage(), 200)], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Abre a autorização da loja no TikTok Shop (só administrador). */
    public function conectarTikTok(Request $request, TikTokShopClient $api): RedirectResponse
    {
        abort_unless(TikTokShopClient::appConfigurado(), 404);
        $estado = Str::random(40);
        $request->session()->put('tiktok_shop_estado', $estado);

        return redirect()->away($api->urlDeAutorizacao($estado));
    }

    /** Retorno da autorização (URL de callback cadastrada no app do Partner Center). */
    public function retornoTikTok(Request $request, TikTokShopClient $api): RedirectResponse
    {
        $esperado = $request->session()->pull('tiktok_shop_estado');
        $voltar = redirect('/admin/perguntas-marketplace?aba=chat-tiktok');

        if (! $esperado || ! hash_equals($esperado, (string) $request->query('state')) || ! $request->query('code')) {
            return $voltar->with('error', 'Autorização do TikTok Shop inválida ou expirada. Tente conectar de novo.');
        }

        try {
            $conexao = $api->conectar((string) $request->query('code'), $request->user()?->id);
        } catch (Throwable $e) {
            report($e);

            return $voltar->with('error', 'O TikTok Shop não liberou a conexão: '.Str::limit($e->getMessage(), 200));
        }

        return $voltar->with('success', 'Loja do TikTok Shop conectada'.($conexao->shop_nome ? ' ('.$conexao->shop_nome.')' : '').'. As conversas chegam em até 2 minutos.');
    }

    private static function linha(MarketplaceChatConversa $c): array
    {
        return [
            'id' => $c->id,
            'comprador' => $c->comprador_nome,
            'avatar' => $c->comprador_avatar,
            'pedido' => $c->pedido_numero,
            'assunto' => $c->assunto,
            'nao_lidas' => $c->nao_lidas,
            'ultima' => $c->ultima_mensagem_texto,
            'ultima_do_cliente' => $c->ultima_do_cliente,
            'ultima_em' => $c->ultima_mensagem_em?->toIso8601String(),
        ];
    }
}

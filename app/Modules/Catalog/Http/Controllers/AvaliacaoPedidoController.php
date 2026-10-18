<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\AvaliacaoSolicitacao;
use App\Modules\Catalog\Support\SolicitacaoAvaliacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página própria de avaliação do pedido (pedido do Lira 2026-10-18): o e-mail
 * pós-compra leva a /avaliar-pedido/{token}, sem login (o token do e-mail é a
 * prova de que a pessoa recebeu o pedido). O cliente dá as estrelas de cada
 * produto e, ao enviar, ganha na hora o cupom com o desconto da nota.
 */
class AvaliacaoPedidoController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $solicitacao = $this->solicitacao($token);
        $order = $solicitacao->order;
        $config = SolicitacaoAvaliacao::config();
        $cupom = $solicitacao->coupon;

        return Inertia::render('Catalog/AvaliarPedido', [
            'token' => $token,
            'pedido' => (string) $order->numero,
            'nome' => SolicitacaoAvaliacao::valores($order, $config, [])['{nome}'],
            'produtos' => SolicitacaoAvaliacao::produtosDaPagina($order),
            'tabela' => $config['cupom_ativo'] ? $config['cupom_por_estrela'] : [],
            'inicial' => [
                'produto' => $request->integer('produto') ?: null,
                'nota' => min(5, max(0, $request->integer('nota'))) ?: null,
            ],
            'resultado' => $solicitacao->nota === null ? null : [
                'nota' => $solicitacao->nota,
                'cupom' => $cupom ? [
                    'codigo' => $cupom->code,
                    'desconto' => $cupom->descricaoDesconto(),
                    'validade' => $cupom->expires_at?->format('d/m/Y'),
                    'minimo' => $cupom->min_order_value ? (float) $cupom->min_order_value : null,
                    'link' => route('catalogo.inicio', ['cupom' => $cupom->code]),
                ] : null,
            ],
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $solicitacao = $this->solicitacao($token);

        $dados = $request->validate([
            'avaliacoes' => ['required', 'array', 'min:1', 'max:30'],
            'avaliacoes.*.product_id' => ['required', 'integer'],
            'avaliacoes.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'avaliacoes.*.comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'avaliacoes.required' => 'Escolha as estrelas de pelo menos um produto.',
            'avaliacoes.*.rating.required' => 'Escolha de 1 a 5 estrelas.',
        ]);

        $avaliacoes = collect($dados['avaliacoes'])
            ->keyBy(fn ($a) => (int) $a['product_id'])
            ->map(fn ($a) => ['rating' => (int) $a['rating'], 'comment' => $a['comment'] ?? null])
            ->all();

        if ($solicitacao->nota !== null) {
            return redirect()->route('avaliacoes.pedido', $token);
        }
        if (! SolicitacaoAvaliacao::registrarAvaliacao($solicitacao, $avaliacoes)) {
            return back()->withErrors(['avaliacoes' => 'Não encontramos esses produtos no seu pedido. Recarregue a página e tente de novo.']);
        }

        return redirect()->route('avaliacoes.pedido', $token)->with('success', 'Avaliação enviada, obrigado!');
    }

    /** Só pedido que recebeu o e-mail; link errado ou de pedido pulado = 404. */
    private function solicitacao(string $token): AvaliacaoSolicitacao
    {
        return AvaliacaoSolicitacao::query()
            ->with(['order.user', 'coupon'])
            ->where('token', $token)
            ->where(fn ($q) => $q->where('status', AvaliacaoSolicitacao::STATUS_ENVIADO)->orWhereNotNull('nota'))
            ->whereNotNull('user_id')
            ->whereHas('order')
            ->firstOrFail();
    }
}

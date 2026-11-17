<?php

namespace App\Modules\Suporte\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Suporte\Models\SolicitacaoCancelamento;
use App\Modules\Suporte\Services\SolicitacoesDeCancelamento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Atendimento > Cancelamentos (Lira, 2026-11-17): pedidos de cancelamento
 * de compras já postadas, feitos pelo botão de Meus pedidos. Cada um tem um
 * chamado em Suporte > Chamados onde a equipe conversa com o cliente.
 */
class AdminCancelamentosController extends Controller
{
    public function index(Request $request): Response
    {
        $filtro = in_array($request->query('status'), [SolicitacaoCancelamento::PENDENTE, SolicitacaoCancelamento::RESOLVIDO, 'todos'], true)
            ? $request->query('status')
            : SolicitacaoCancelamento::PENDENTE;

        $linhas = SolicitacaoCancelamento::query()
            ->with(['order:id,external_order_id,total,status,shipping_name', 'user:id,name,email', 'chamado:id,numero', 'resolvidoPor:id,name'])
            ->when($filtro !== 'todos', fn ($q) => $q->where('status', $filtro))
            ->orderByRaw('case when status = ? then 0 else 1 end', [SolicitacaoCancelamento::PENDENTE])
            ->orderByDesc('solicitado_em')
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (SolicitacaoCancelamento $s) => [
                'id' => $s->id,
                'status' => $s->status,
                'motivo' => $s->motivo,
                'solicitado_em' => ($s->solicitado_em ?? $s->created_at)?->toIso8601String(),
                'postado_em' => $s->postado_em?->toIso8601String(),
                'postagem_fonte' => $s->postagem_fonte,
                'rastreio' => $s->rastreio,
                'resolvido_em' => $s->resolvido_em?->toIso8601String(),
                'resolvido_por' => $s->resolvidoPor?->name,
                'pedido' => $s->order ? [
                    'id' => $s->order->id,
                    'numero' => $s->order->numero,
                    'total' => (float) $s->order->total,
                    'status' => $s->order->status,
                ] : null,
                'cliente' => $s->user
                    ? ['nome' => $s->user->name, 'email' => $s->user->email]
                    : ['nome' => $s->order?->shipping_name, 'email' => null],
                'chamado' => $s->chamado ? ['id' => $s->chamado->id, 'numero' => $s->chamado->numero] : null,
            ]);

        return Inertia::render('Admin/Suporte/Cancelamentos', [
            'solicitacoes' => $linhas,
            'filtro' => $filtro,
            'contagem' => SolicitacaoCancelamento::query()->selectRaw('status, count(*) total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function resolver(Request $request, SolicitacaoCancelamento $solicitacao, SolicitacoesDeCancelamento $servico): RedirectResponse
    {
        $servico->resolver($solicitacao, $request->user());

        return back()->with('success', 'Solicitação de cancelamento marcada como resolvida.');
    }
}
